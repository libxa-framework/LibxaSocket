<?php

declare(strict_types=1);

namespace LibxaSocket\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class WsMiddleware
{
    public function __construct(
        public array|string $middleware
    ) {}
}
