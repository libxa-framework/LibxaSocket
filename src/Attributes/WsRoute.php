<?php

declare(strict_types=1);

namespace LibxaSocket\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class WsRoute
{
    public function __construct(
        public string $uri,
        public ?string $name = null,
    ) {}
}
