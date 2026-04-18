<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\GuardPolicy;

final class RuntimeOptions
{
    public bool $strict = false;

    public GuardPolicy $guardPolicy;

    public function __construct()
    {
        $this->guardPolicy = new GuardPolicy();
    }
}
