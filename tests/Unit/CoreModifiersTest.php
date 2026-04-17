<?php

declare(strict_types=1);

use Bugo\Antlers\Modifiers\CoreModifiers;
use Bugo\Antlers\Modifiers\ModifierRegistry;

it('registers the standalone mandatory modifiers from the spec', function (): void {
    $registry = new ModifierRegistry();

    CoreModifiers::register($registry);

    $requiredModifiers = [
        'upper',
        'lower',
        'title',
        'ucfirst',
        'lcfirst',
        'trim',
        'replace',
        'regex_replace',
        'truncate',
        'limit',
        'strip_tags',
        'sanitize',
        'entities',
        'slugify',
        'snake',
        'kebab',
        'studly',
        'word_count',
        'nl2br',
        'count',
        'length',
        'first',
        'last',
        'keys',
        'values',
        'join',
        'explode',
        'pluck',
        'sort',
        'reverse',
        'unique',
        'flatten',
        'chunk',
        'where',
        'pad',
        'add',
        'subtract',
        'multiply',
        'divide',
        'mod',
        'round',
        'floor',
        'ceil',
        'is_array',
        'is_empty',
        'is_numeric',
        'markdown',
    ];

    foreach ($requiredModifiers as $modifier) {
        expect($registry->has($modifier))->toBeTrue("Modifier [$modifier] should be registered.");
    }
});
