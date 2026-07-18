<?php

declare(strict_types=1);

namespace LibxaSocket\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class WsValidate
{
    public function __construct(
        public string $requestClass
    ) {}
}
