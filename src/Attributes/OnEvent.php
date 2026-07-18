<?php

declare(strict_types=1);

namespace LibxaSocket\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class OnEvent
{
    public function __construct(
        public string $event,
        public ?string $dto = null
    ) {}
}
