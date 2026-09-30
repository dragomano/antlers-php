<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use Bugo\Antlers\Runtime\RuntimeOptions;

/**
 * Registers the opt-in date/time modifiers (`timestamp`, `ago`).
 */
final class DateModifiers
{
    public const NAMES = ['timestamp', 'ago'];

    public static function register(ModifierRegistry $registry, RuntimeOptions $options): void
    {
        // Core must be present even when this group is the first registration to touch
        // the registry: registerGroup() would otherwise consume the lazy loader inside
        // its name filter and drop every name outside it.
        CoreModifiers::register($registry, $options);

        $registry->registerGroup(self::NAMES, static function () use ($registry, $options): void {
            CoreModifiers::registerAll($registry, $options);
        });
    }
}
