<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $room }} — LibxaSocket</title>
    <style>
        :root { color-scheme: light dark; --line: #8883; --dim: #7a7a7a; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.55 ui-sans-serif, system-ui, sans-serif; }
        .wrap { max-width: 60rem; margin: 0 auto; padding: 1.5rem 1rem 2rem; }
        header { display: flex; align-items: baseline; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem; }
        h1 { font-size: 1.15rem; margin: 0; }
        .status { font-size: .8rem; padding: .15rem .5rem; border-radius: 999px; border: 1px solid var(--line); }
        .status[data-state="connected"] { color: #0a7d3c; border-color: #0a7d3c66; }
        .status[data-state="failed"] { color: #b3261e; border-color: #b3261e66; }
        .grid { display: grid; grid-template-columns: 1fr 14rem; gap: 1rem; align-items: start; }
        @media (max-width: 46rem) { .grid { grid-template-columns: 1fr; } }
        .panel { border: 1px solid var(--line); border-radius: .6rem; overflow: hidden; }
        .panel h2 { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: var(--dim);
                    margin: 0; padding: .6rem .8rem; border-bottom: 1px solid var(--line); }
        #log { height: 26rem; overflow-y: auto; padding: .5rem .8rem; margin: 0; list-style: none; }
        #log li { padding: .3rem 0; border-bottom: 1px solid var(--line); }
        #log li:last-child { border-bottom: 0; }
        #log .who { font-weight: 600; }
        #log .at { color: var(--dim); font-size: .75rem; margin-left: .4rem; }
        #log .system { color: var(--dim); font-style: italic; }
        #members { margin: 0; padding: .5rem .8rem; list-style: none; }
        #members li { padding: .2rem 0; }
        form { display: flex; gap: .5rem; margin-top: .75rem; }
        input[type=text] { flex: 1; padding: .55rem .7rem; border: 1px solid var(--line);
                           border-radius: .4rem; background: transparent; color: inherit; font: inherit; }
        button { padding: .55rem 1.1rem; border: 0; border-radius: .4rem; background: #0053db;
                 color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:disabled { opacity: .5; cursor: default; }
        .hint { color: var(--dim); font-size: .8rem; margin-top: 1rem; }
        code { font-size: .85em; background: #8881; padding: .1rem .3rem; border-radius: .2rem; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>#{{ $room }}</h1>
        <span class="status" id="status" data-state="connecting">connecting…</span>
        <span style="margin-left:auto;color:var(--dim);font-size:.85rem">you are {{ $me['name'] }}</span>
    </header>

    <div class="grid">
        <div class="panel">
            <h2>Messages</h2>
            <ul id="log"></ul>
        </div>
        <div class="panel">
            <h2>In the room (<span id="count">0</span>)</h2>
            <ul id="members"></ul>
        </div>
    </div>

    <form id="composer" autocomplete="off">
        <input type="text" id="body" placeholder="Say something…" maxlength="500" required>
        <button type="submit">Send</button>
    </form>

    <p class="hint">
        Open this page in a second window to see presence and delivery. Messages go
        <code>POST /chat/{{ $room }}</code> → <code>broadcast()</code> → the socket server → every window,
        including the one that sent it.
    </p>
</div>

<script>
/*
 * A minimal Pusher-protocol client.
 *
 * Written out rather than pulling in pusher-js so the protocol is visible:
 * this is every message the wire format involves. Laravel Echo speaks exactly
 * this, so swapping it in is a configuration change, not a rewrite.
 */
const config = {
    key:   @json($socketKey),
    host:  @json($socketHost),
    port:  @json($socketPort),
    room:  @json($room),
    token: @json(csrf_token()),
};

const channelName = `presence-room.${config.room}`;

const statusEl  = document.getElementById('status');
const logEl     = document.getElementById('log');
const membersEl = document.getElementById('members');
const countEl   = document.getElementById('count');

const members = new Map();

function setStatus(text, state) {
    statusEl.textContent = text;
    statusEl.dataset.state = state;
}

function line(html, className = '') {
    const li = document.createElement('li');
    if (className) li.className = className;
    li.innerHTML = html;
    logEl.appendChild(li);
    logEl.scrollTop = logEl.scrollHeight;
}

function escape(value) {
    return String(value).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function renderMembers() {
    membersEl.innerHTML = '';
    countEl.textContent = members.size;

    for (const info of members.values()) {
        const li = document.createElement('li');
        li.textContent = info.name ?? 'Someone';
        membersEl.appendChild(li);
    }
}

let socket;
let socketId = null;
let backoff = 500;

function connect() {
    setStatus('connecting…', 'connecting');

    socket = new WebSocket(`ws://${config.host}:${config.port}/app/${config.key}`);

    socket.onopen = () => { backoff = 500; };

    socket.onclose = () => {
        setStatus('reconnecting…', 'connecting');
        members.clear();
        renderMembers();

        // Backing off rather than hammering: a server that is down stays down
        // faster when every open tab retries ten times a second.
        setTimeout(connect, backoff);
        backoff = Math.min(backoff * 2, 10000);
    };

    socket.onerror = () => setStatus('connection failed', 'failed');

    socket.onmessage = async (raw) => {
        const message = JSON.parse(raw.data);

        // `data` is a JSON string on the wire, per the protocol.
        const data = typeof message.data === 'string' && message.data.length
            ? JSON.parse(message.data)
            : (message.data ?? {});

        switch (message.event) {
            case 'pusher:connection_established':
                socketId = data.socket_id;
                setStatus('connected', 'connected');
                await subscribe();
                break;

            case 'pusher:ping':
                socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                break;

            case 'pusher:error':
                setStatus(`error: ${data.message ?? 'unknown'}`, 'failed');
                line(`<span class="system">${escape(data.message ?? 'protocol error')}</span>`, 'system');
                break;

            case 'pusher_internal:subscription_succeeded':
                members.clear();
                for (const [id, info] of Object.entries(data.presence?.hash ?? {})) {
                    members.set(id, info);
                }
                renderMembers();
                line('<span class="system">You joined.</span>');
                break;

            case 'pusher_internal:member_added':
                members.set(data.user_id, data.user_info ?? {});
                renderMembers();
                line(`<span class="system">${escape(data.user_info?.name ?? 'Someone')} joined.</span>`);
                break;

            case 'pusher_internal:member_removed':
                line(`<span class="system">${escape(members.get(data.user_id)?.name ?? 'Someone')} left.</span>`);
                members.delete(data.user_id);
                renderMembers();
                break;

            case 'MessagePosted':
                line(`<span class="who">${escape(data.author)}</span>: ${escape(data.body)}<span class="at">${escape(data.posted_at)}</span>`);
                break;

            case 'client-typing':
                setStatus(`${data.name} is typing…`, 'connected');
                clearTimeout(window.__typing);
                window.__typing = setTimeout(() => setStatus('connected', 'connected'), 1200);
                break;
        }
    };
}

/*
 * A presence channel cannot be joined on the browser's say-so. This asks the
 * application whether we may, over HTTP with our session, and the application
 * answers with a signature the socket server will accept.
 */
async function subscribe() {
    const response = await fetch('/broadcasting/auth', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.token },
        body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
    });

    if (!response.ok) {
        setStatus('not authorized', 'failed');
        line('<span class="system">The server would not authorize this channel.</span>');
        return;
    }

    const auth = await response.json();

    socket.send(JSON.stringify({
        event: 'pusher:subscribe',
        data: { channel: channelName, auth: auth.auth, channel_data: auth.channel_data },
    }));
}

document.getElementById('composer').addEventListener('submit', async (event) => {
    event.preventDefault();

    const input = document.getElementById('body');
    const body = input.value.trim();
    if (!body) return;

    input.value = '';

    await fetch(`/chat/${config.room}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.token },
        body: JSON.stringify({ body }),
    });
    // Nothing is drawn here. The message arrives over the socket like
    // everybody else's, so there is one path in and nothing to reconcile.
});

// A client event: straight to the other browsers in the channel, never
// touching the application. Only possible because this is a presence channel.
document.getElementById('body').addEventListener('input', () => {
    if (socket?.readyState !== WebSocket.OPEN) return;

    clearTimeout(window.__typingSend);
    window.__typingSend = setTimeout(() => {
        socket.send(JSON.stringify({
            event: 'client-typing',
            channel: channelName,
            data: { name: @json($me['name']) },
        }));
    }, 250);
});

connect();
</script>
</body>
</html>
