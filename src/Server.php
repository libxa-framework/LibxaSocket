<?php

declare(strict_types=1);

namespace LibxaSocket;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Libxa\Foundation\Application;

/**
 * LibxaFrame Reactive WebSocket Server (powered by Workerman)
 *
 * Runs a persistent WebSocket server that manages reactive component state.
 * Works in tandem with @reactive Blade directive and the JS client.
 *
 * Usage:
 *   php Libxa ws:serve
 *
 * Client (JS) sends:
 *   { "type": "call", "component": "Counter", "method": "increment", "params": {} }
 *
 * Server responds:
 *   { "type": "patch", "component": "Counter", "id": "abc123", "diff": [...] }
 */
class Server
{
    protected Worker $worker;

    /** Active component instances per connection: [connectionId => [componentId => ReactiveComponent]] */
    protected array $components = [];

    /** Channel subscriptions: [channel => [connection, ...]] */
    protected array $channels = [];

    /** Custom WsConnections indexed by Workerman connection ID */
    protected array $wsConnections = [];

    /** Managed channel instances per connection: [connectionId => WsChannel] */
    protected array $activeChannels = [];

    protected \Libxa\Foundation\Application $app;

    /** @var array<string, array<string, TcpConnection>> Rooms and their subscribed connections */
    protected array $rooms = [];

    /** @var \Closure|null Custom handler for new WebSocket connections */
    public ?\Closure $onWebSocketConnect = null;

    public function __construct(
        protected ?string $host    = null,
        protected ?int    $port    = null,
        protected ?int    $workers = null,
        ?Application      $app     = null
    ) {
        $this->host    = $host    ?? env('SOCKET_HOST', env('WS_HOST', '0.0.0.0'));
        $this->port    = $port    ?? (int) env('SOCKET_PORT', env('WS_PORT', 8080));
        $this->workers = $workers ?? (int) env('SOCKET_WORKERS', env('WS_WORKERS', 4));
        
        if ($app) {
            $this->app = $app;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Boot
    // ─────────────────────────────────────────────────────────────────

    /**
     * Path to the file used to broadcast a "restart" signal from
     * `socket:restart` to any running `socket:start` worker processes.
     */
    public function restartSignalPath(): string
    {
        $storagePath = function_exists('storage_path') ? storage_path('framework/cache') : (getcwd() . '/storage/framework/cache');

        return $storagePath . '/socket-restart';
    }

    public function start(): void
    {
        $this->worker         = new Worker("websocket://{$this->host}:{$this->port}");
        $this->worker->count  = $this->workers;
        $this->worker->name   = 'LibxaFrame-WS';
        
        // This is crucial for room management
        $this->rooms = [];

        $this->worker->onConnect         = $this->onConnect(...);
        // $this->worker->onWebSocketConnect = $this->onWebSocketConnect(...); // Standard Workerman doesn't have this on Worker, but protocol looks for it
        // We'll set it on the worker instance because the websocket protocol implementation looks for it there specifically
        $this->worker->onWebSocketConnect = $this->onWebSocketConnectHandler(...);
        $this->worker->onMessage         = $this->onMessage(...);
        $this->worker->onClose           = $this->onClose(...);
        $this->worker->onError           = $this->onError(...);
        $this->worker->onWorkerStart     = $this->onWorkerStart(...);

        // --- Internal IPC Control Channel ---
        // Workerman on Windows doesn't support multiple workers in one file.
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        if (!$isWindows) {
            $internalPort = (int) env('SOCKET_INTERNAL_PORT', env('WS_INTERNAL_PORT', 8082));
            $control = new Worker("text://127.0.0.1:{$internalPort}");
            $control->name = 'LibxaFrame-WS-Internal';
            $control->onMessage = function ($connection, $data) {
                $this->processIpcMessage($data);
            };
        }

        Worker::runAll();
    }

    /**
     * Process an IPC message (broadcast command)
     */
    protected function processIpcMessage(string $data): void
    {
        $payload = json_decode($data, true);
        if (isset($payload['command']) && $payload['command'] === 'broadcast') {
            $info = $payload['data'];
            $channels = (array) $info['channels'];
            $event    = $info['event'];
            $data     = $info['payload'];

            foreach ($channels as $channel) {
                $this->broadcastToRoom($channel, $event, $data);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Worker events
    // ─────────────────────────────────────────────────────────────────

    protected function onWorkerStart(Worker $worker): void
    {
        try {
            echo "  [LibxaWS] Worker #{$worker->id} starting pulse sequence...\n";

            // Bootstrap the LibxaFrame application
            $appPath = getcwd() . '/src/bootstrap/app.php';
            if (file_exists($appPath)) {
                $app = require $appPath;
                if ($app instanceof \Libxa\Foundation\Application) {
                    $this->app = $app;
                    $this->app->boot();
                    echo "  [LibxaWS] LibxaFrame Application Bootstrapped Successfully!\n";
                    
                    /** @var \LibxaSocket\WsRouter $router */
                    $router = $this->app->make(\LibxaSocket\WsRouter::class);
                    $path = getcwd() . '/src/app/WebSockets';
                    $router->scanDirectory($path, 'App\\WebSockets');
                    
                    $channels = $router->getChannels();
                    echo "  [LibxaWS] Registered " . count($channels) . " WebSocket channels.\n";
                    foreach (array_keys($channels) as $uri) {
                         echo "  [LibxaWS] Route Active: $uri\n";
                    }
                } else {
                    echo "  [LibxaWS] Warning: app.php did not return an Application instance.\n";
                }
            } else {
                echo "  [LibxaWS] Fatal Error: Bootstrap not found at $appPath\n";
            }

            // Windows IPC Poller (Every 200ms)
            if (DIRECTORY_SEPARATOR === '\\') {
                \Workerman\Lib\Timer::add(0.2, function() {
                    try {
                        $path = storage_path('framework/ws_broadcasts');
                        if (!is_dir($path)) return;

                        $files = glob($path . '/*.json');
                        foreach ($files as $file) {
                            $data = file_get_contents($file);
                            if ($data) {
                                $this->processIpcMessage($data);
                            }
                            @unlink($file);
                        }
                    } catch (\Throwable $ipcE) {
                        // Silent fail for IPC
                    }
                });
            }

            // --- Demo: Global Random Number Stream ---
            // Generates a random number every 2 seconds for testing
            \Workerman\Lib\Timer::add(2.0, function() {
                try {
                    $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0.0) : 0.0;
                    echo "  [LibxaWS] Timer Pulse: Broadcasting to room 'random-stream'...\n";
                    $this->broadcastToRoom('random-stream', 'random.number', [
                        'value' => rand(1, 1000),
                        'timestamp' => date('H:i:s'),
                        'server_load' => $load
                    ]);
                } catch (\Throwable $te) {
                    echo "  [LibxaWS] Timer Error: " . $te->getMessage() . "\n";
                }
            });
            
            echo "  [LibxaWS] Worker #{$worker->id} is now heartbeating on port {$this->port}\n";

            // Restart signal poller (checked every 2s) — lets `socket:restart`
            // gracefully stop this worker without killing it forcibly.
            // Workerman's own process manager (on non-Windows) will restart
            // any worker that exits, so a clean stopAll() here is enough.
            $restartSignalPath = $this->restartSignalPath();
            $bootedRestartSignal = is_file($restartSignalPath) ? @file_get_contents($restartSignalPath) : null;

            \Workerman\Lib\Timer::add(2, function () use ($restartSignalPath, $bootedRestartSignal) {
                if (! is_file($restartSignalPath)) {
                    return;
                }

                $current = @file_get_contents($restartSignalPath);
                if ($current !== false && $current !== $bootedRestartSignal) {
                    echo "  [LibxaWS] Restart signal received, stopping worker...\n";
                    Worker::stopAll();
                }
            });
        } catch (\Throwable $e) {
            echo "  [LibxaWS] CRITICAL BOOT ERROR: " . $e->getMessage() . "\n";
            echo "  [LibxaWS] Trace: " . $e->getTraceAsString() . "\n";
        }
    }

    protected function onConnect(TcpConnection $connection): void
    {
        $this->components[$connection->id] = [];
    }


    protected function onClose(TcpConnection $connection): void
    {
        // Cleanup reactive components
        unset($this->components[$connection->id]);

        // Cleanup general channels
        if (isset($this->activeChannels[$connection->id])) {
            $wsConn  = $this->wsConnections[$connection->id];
            $channel = $this->activeChannels[$connection->id];
            
            try {
                $channel->onClose($wsConn);
            } catch (\Throwable $e) {
                $channel->onError($wsConn, $e);
            }

            // Cleanup rooms logic from WsConnection
            foreach ($this->rooms as $room => &$conns) {
                unset($conns[$connection->id]);
            }

            unset($this->activeChannels[$connection->id]);
            unset($this->wsConnections[$connection->id]);
        }

        // Remove from legacy reactive channels
        foreach ($this->channels as $channelName => &$conns) {
            $conns = array_filter($conns, fn($c) => $c->id !== $connection->id);
        }
    }

    protected function onError(TcpConnection $connection, int $code, string $message): void
    {
        echo "  [LibxaWS] Error on #{$connection->id}: [$code] $message\n";
        
        if (isset($this->activeChannels[$connection->id])) {
            $wsConn  = $this->wsConnections[$connection->id];
            $channel = $this->activeChannels[$connection->id];
            $channel->onError($wsConn, new \Exception($message, $code));
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Message routing
    // ─────────────────────────────────────────────────────────────────

    protected function onMessage(TcpConnection $connection, string $data): void
    {
        // 1. Handle regular WebSocket channels first
        if (isset($this->activeChannels[$connection->id])) {
            $wsConn  = $this->wsConnections[$connection->id];
            $channel = $this->activeChannels[$connection->id];
            $msg     = \LibxaSocket\WsMessage::parse($data);

            if ($msg) {
                try {
                    // Route to attribute-based event handler if exists
                    if (!$this->routeToHandler($channel, $wsConn, $msg)) {
                        $channel->onMessage($wsConn, $msg);
                    }
                } catch (\Throwable $e) {
                    $channel->onError($wsConn, $e);
                }
            }
            return;
        }

        // 2. Fallback to Reactive Component routing
        $message = json_decode($data, true);

        if (! is_array($message) || ! isset($message['type'])) {
            $this->sendError($connection, 'Invalid message format');
            return;
        }

        try {
            match ($message['type']) {
                'mount'           => $this->handleMount($connection, $message),
                'call'            => $this->handleCall($connection, $message),
                'update_property' => $this->handleCall($connection, $message),
                'subscribe'       => $this->handleSubscribe($connection, $message),
                'unsubscribe'     => $this->handleUnsubscribe($connection, $message),
                'ping'            => $this->send($connection, ['type' => 'pong']),
                default           => $this->sendError($connection, "Unknown message type: {$message['type']}"),
            };
        } catch (\Throwable $e) {
            $this->sendError($connection, $e->getMessage());
        }
    }

    /**
     * Attempt to route message to a method marked with #[OnEvent]
     */
    protected function routeToHandler(\LibxaSocket\WsChannel $channel, \LibxaSocket\WsConnection $conn, \LibxaSocket\WsMessage $msg): bool
    {
        $reflection = new \ReflectionClass($channel);
        $eventName  = $msg->event();

        foreach ($reflection->getMethods() as $method) {
            $attrs = $method->getAttributes(\LibxaSocket\Attributes\OnEvent::class);
            foreach ($attrs as $attr) {
                $onEvent = $attr->newInstance();
                if ($onEvent->event === $eventName) {
                    
                    $params = [$conn];
                    
                    // Handle typed DTO if requested
                    if ($onEvent->dto && class_exists($onEvent->dto)) {
                        $params[] = $onEvent->dto::fromArray($msg->all());
                    } else {
                        $params[] = $msg;
                    }

                    $method->invokeArgs($channel, $params);
                    return true;
                }
            }
        }

        return false;
    }


    // ─────────────────────────────────────────────────────────────────
    //  Reactive component handlers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Mount a reactive component on a connection.
     */
    protected function handleMount(TcpConnection $connection, array $message): void
    {
        $class       = $message['component'] ?? null;
        $id          = $message['id'] ?? uniqid('rc_');
        $props       = $message['props'] ?? $message['state'] ?? [];
        $checksum    = $message['checksum'] ?? null;

        if ($class === null) {
            $this->sendError($connection, "Component class not specified.");
            return;
        }

        try {
            // Support for LiveLib discovery
            if (class_exists(\Libxa\LiveLib\ComponentRegistry::class)) {
                $class = \Libxa\LiveLib\ComponentRegistry::getClass($class);
            }

            if (! class_exists($class)) {
                $this->sendError($connection, "Component [$class] not found.");
                return;
            }

            /** @var \Libxa\LiveLib\ReactiveComponent|ReactiveComponent $component */
            if ($checksum && class_exists(\Libxa\LiveLib\Hydration\Hydrator::class)) {
                // Hydrate existing state
                $component = \Libxa\LiveLib\Hydration\Hydrator::hydrate($class, $id, $props, $checksum);
            } else {
                // New mount
                $component = new $class($props);
                $component->id = $id;
                $component->mount($props);
            }

            $this->components[$connection->id][$id] = $component;

            $this->send($connection, [
                'type'      => 'snapshot',
                'id'        => $id,
                'state'     => method_exists($component, 'getState') ? $component->getState() : $component->getPublicState(),
                'checksum'  => class_exists(\Libxa\LiveLib\Hydration\Hydrator::class) && $component instanceof \Libxa\LiveLib\ReactiveComponent 
                                ? \Libxa\LiveLib\Hydration\Hydrator::dehydrate($component)['checksum'] 
                                : null,
                'html'      => method_exists($component, 'renderHtml') ? $component->renderHtml() : $component->render(),
            ]);

        } catch (\Throwable $e) {
            $this->sendError($connection, "Mount Error: " . $e->getMessage());
        }
    }

    /**
     * Call a method on an already-mounted component.
     */
    protected function handleCall(TcpConnection $connection, array $message): void
    {
        $id     = $message['id']     ?? null;
        $method = $message['method'] ?? null;
        $params = $message['params'] ?? [];

        if ($id === null || ! isset($this->components[$connection->id][$id])) {
            $this->sendError($connection, "Component [$id] not mounted.");
            return;
        }

        /** @var \Libxa\LiveLib\ReactiveComponent|ReactiveComponent $component */
        $component = $this->components[$connection->id][$id];

        try {
            if ($message['type'] === 'update_property') {
                $prop = $message['property'];
                $val  = $message['value'];
                
                if (method_exists($component, 'updating')) $component->updating($prop, $val);
                $component->$prop = $val;
                if (method_exists($component, 'updated')) $component->updated($prop, $val);
            } else {
                if (! method_exists($component, $method)) {
                    $this->sendError($connection, "Method [$method] not found.");
                    return;
                }
                $component->$method(...(array) $params);
            }

            $html = method_exists($component, 'renderHtml') ? $component->renderHtml() : $component->render();
            $state = method_exists($component, 'getState') ? $component->getState() : $component->getPublicState();
            
            $response = [
                'type'  => 'patch',
                'id'    => $id,
                'state' => $state,
                'html'  => $html, // For now, send full HTML, JS client handles morphing
            ];

            if (class_exists(\Libxa\LiveLib\Hydration\Hydrator::class) && $component instanceof \Libxa\LiveLib\ReactiveComponent) {
                $response['checksum'] = \Libxa\LiveLib\Hydration\Hydrator::dehydrate($component)['checksum'];
            }

            // Handle dispatched events
            if (method_exists($component, 'pullDispatched')) {
                foreach ($component->pullDispatched() as $event) {
                    $this->send($connection, ['type' => 'event', 'event' => $event['event'], 'data' => $event['data']]);
                }
            }

            $this->send($connection, $response);

        } catch (\Throwable $e) {
            $this->sendError($connection, "Call Error: " . $e->getMessage());
        }
    }

    /**
     * Subscribe to a named broadcast channel.
     */
    protected function handleSubscribe(TcpConnection $connection, array $message): void
    {
        $channel = $message['channel'] ?? null;

        if ($channel) {
            $this->channels[$channel][] = $connection;

            $this->send($connection, [
                'type'    => 'subscribed',
                'channel' => $channel,
            ]);
        }
    }

    protected function handleUnsubscribe(TcpConnection $connection, array $message): void
    {
        $channel = $message['channel'] ?? null;

        if ($channel && isset($this->channels[$channel])) {
            $this->channels[$channel] = array_filter(
                $this->channels[$channel],
                fn($c) => $c->id !== $connection->id
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Broadcasting
    // ─────────────────────────────────────────────────────────────────

    /**
     * Broadcast an event to all connections in a specific room.
     */
    public function broadcastToRoom(string $room, string $event, array $data = []): void
    {
        $payload = json_encode(['event' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE);
        
        if (isset($this->rooms[$room])) {
            foreach ($this->rooms[$room] as $connection) {
                $connection->send($payload);
            }
        }
    }

    /**
     * Broadcast a message to all subscribers on a channel.
     */
    public function broadcast(string $channel, array $data): void
    {
        if (! isset($this->channels[$channel])) return;

        foreach ($this->channels[$channel] as $connection) {
            $this->send($connection, array_merge(['channel' => $channel], $data));
        }
    }

    /**
     * Broadcast to ALL connected clients.
     */
    public function broadcastAll(array $data): void
    {
        foreach ($this->worker->connections as $connection) {
            $this->send($connection, $data);
        }
    }

    public function onWebSocketConnectHandler(TcpConnection $connection, string $httpHeader): void
    {
        try {
            // Parse the request URI from the HTTP header
            if (!preg_match("/GET (.*) HTTP/", $httpHeader, $match)) {
                $connection->close();
                return;
            }

            $uri = parse_url($match[1], PHP_URL_PATH);
            echo "  [LibxaWS] Handshake Attempt: $uri\n";
            
            /** @var \LibxaSocket\WsRouter $router */
            $router = $this->app->make(\LibxaSocket\WsRouter::class);
            $match  = $router->match($uri);

            if ($match) {
                [$class, $params] = $match;
                echo "  [LibxaWS] Match Found: $class\n";
                $channel = $this->app->make($class);
                
                $wsConn = new \LibxaSocket\WsConnection($connection, $this);
                $wsConn->setParams($params);
                
                $this->activeChannels[$connection->id] = $channel;
                $this->wsConnections[$connection->id]  = $wsConn;

                // Trigger onOpen
                $channel->onOpen($wsConn);
            }

            if (isset($this->onWebSocketConnect)) {
                ($this->onWebSocketConnect)($connection, $httpHeader);
            }
        } catch (\Throwable $e) {
            echo "  [LibxaWS] Handshake Error: " . $e->getMessage() . "\n";
            $connection->close();
        }
    }

    public function joinRoom(string $room, string $id, TcpConnection $connection): void
    {
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }
        $this->rooms[$room][$id] = $connection;
        echo "  [LibxaWS] Client #$id joined room: $room\n";
    }

    public function leaveRoom(string $room, string $id): void
    {
        if (isset($this->rooms[$room])) {
            unset($this->rooms[$room][$id]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────

    protected function send(TcpConnection $connection, array $data): void
    {
        $connection->send(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    protected function sendError(TcpConnection $connection, string $message): void
    {
        $this->send($connection, ['type' => 'error', 'message' => $message]);
    }
}
