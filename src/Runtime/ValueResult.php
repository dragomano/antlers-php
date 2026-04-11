<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

final readonly class ValueResult
{
    public function __construct(public mixed $value) {}
}
