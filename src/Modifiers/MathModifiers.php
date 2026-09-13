<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use Bugo\Antlers\Runtime\RuntimeOptions;

final class MathModifiers
{
    public const NAMES = ['add', 'subtract', 'multiply', 'divide', 'mod', 'ceil', 'floor', 'round'];

    public static function register(ModifierRegistry $registry, RuntimeOptions $options): void
    {
        $registry->registerGroup(self::NAMES, static function () use ($registry, $options): void {
            CoreModifiers::registerAll($registry, $options);
        });
    }
}
