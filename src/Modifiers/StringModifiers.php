<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use Bugo\Antlers\Runtime\RuntimeOptions;

final class StringModifiers
{
    public const NAMES = ['upper', 'lower', 'ucfirst', 'lcfirst', 'title', 'trim', 'word_count', 'slugify', 'snake', 'studly', 'kebab', 'truncate', 'limit', 'replace', 'regex_replace', 'nl2br', 'strip_tags', 'entities', 'sanitize', 'decode', 'wrap', 'surround', 'starts_with', 'ends_with', 'contains', 'repeat', 'pad'];

    public static function register(ModifierRegistry $registry, RuntimeOptions $options): void
    {
        $registry->registerGroup(self::NAMES, static function () use ($registry, $options): void {
            CoreModifiers::registerAll($registry, $options);
        });
    }
}
