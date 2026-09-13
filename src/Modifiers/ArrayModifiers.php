<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use Bugo\Antlers\Runtime\RuntimeOptions;

final class ArrayModifiers
{
    public const NAMES = ['reverse', 'length', 'count', 'sort', 'first', 'last', 'pluck', 'unique', 'flatten', 'keys', 'values', 'where', 'chunk', 'join', 'explode'];

    public static function register(ModifierRegistry $registry, RuntimeOptions $options): void
    {
        $registry->registerGroup(self::NAMES, static function () use ($registry, $options): void {
            CoreModifiers::registerAll($registry, $options);
        });
    }
}
