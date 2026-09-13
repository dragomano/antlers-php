<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

final class CompositionTags
{
    public const NAMES = ['partial', 'layout', 'section', 'yield', 'slot', 'stack', 'push', 'prepend', 'once'];

    public static function register(TagRegistry $registry): void
    {
        CoreTags::registerNames($registry, self::NAMES);
    }
}
