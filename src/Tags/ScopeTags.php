<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

final class ScopeTags
{
    public const NAMES = ['switch', 'scope', 'increment'];

    public static function register(TagRegistry $registry): void
    {
        CoreTags::registerNames($registry, self::NAMES);
    }
}
