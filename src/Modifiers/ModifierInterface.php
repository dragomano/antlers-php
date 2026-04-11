<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

interface ModifierInterface
{
    /**
     * @param list<mixed> $params  Modifier parameters (e.g., [:50, :' ...'] from | truncate:50:' ...')
     * @param array<string, mixed> $context Current template scope
     */
    public function modify(mixed $value, array $params, array $context): mixed;
}
