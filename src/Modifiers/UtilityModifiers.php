<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use Bugo\Antlers\Runtime\RuntimeOptions;

final class UtilityModifiers
{
    public const NAMES = ['is_empty', 'is_array', 'is_numeric', 'type_of', 'md5', 'format'];

    public static function register(ModifierRegistry $registry, RuntimeOptions $options): void
    {
        $registry->registerGroup(self::NAMES, static function () use ($registry, $options): void {
            CoreModifiers::registerAll($registry, $options);
        });
    }
}
