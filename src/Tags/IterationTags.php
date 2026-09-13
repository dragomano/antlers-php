<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

final class IterationTags
{
    public const NAMES = ['foreach', 'loop'];

    public static function register(TagRegistry $registry): void
    {
        CoreTags::registerNames($registry, self::NAMES);
    }
}
