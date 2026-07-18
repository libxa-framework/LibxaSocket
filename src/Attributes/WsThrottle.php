<?php

declare(strict_types=1);

namespace LibxaSocket\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class WsThrottle
{
    public function __construct(
        public string $limit
    ) {}
}
