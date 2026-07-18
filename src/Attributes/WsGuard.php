<?php

declare(strict_types=1);

namespace LibxaSocket\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class WsGuard
{
    public function __construct(
        public string $guard
    ) {}
}
