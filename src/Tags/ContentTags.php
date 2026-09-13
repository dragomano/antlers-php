<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

final class ContentTags
{
    public const NAMES = ['markdown', 'dump', 'svg'];

    public static function register(TagRegistry $registry): void
    {
        CoreTags::registerNames($registry, self::NAMES);
    }
}
