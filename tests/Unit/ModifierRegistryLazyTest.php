<?php

declare(strict_types=1);

use Bugo\Antlers\Modifiers\ModifierRegistry;

it('loads built-in modifiers only when queried', function (): void {
    $registry = new ModifierRegistry();
    $loads    = 0;

    $registry->setLoader(function () use ($registry, &$loads): void {
        $loads++;

        $registry->register('lazy', static fn(mixed $value): mixed => $value);
    });

    expect($loads)->toBe(0)
        ->and($registry->has('lazy'))->toBeTrue()
        ->and($loads)->toBe(1)
        ->and($registry->apply('lazy', 'value', [], []))->toBe('value')
        ->and($loads)->toBe(1);
});

it('loads defaults before a custom modifier overrides them', function (): void {
    $registry = new ModifierRegistry();
    $registry->setLoader(function () use ($registry): void {
        $registry->register('name', static fn(): string => 'default');
    });
    $registry->register('name', static fn(): string => 'custom');

    expect($registry->apply('name', null, [], []))->toBe('custom');
});
