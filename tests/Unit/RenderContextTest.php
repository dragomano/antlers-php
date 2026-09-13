<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\RenderContext;

it('owns scope and state for one render', function (): void {
    $context = new RenderContext(['global' => 'G']);

    $output = $context->renderFrame(['local' => 'L'], function () use ($context): string {
        $context->state->storeSection('head', 'H');

        return $context->scope->all()['global'] . $context->scope->all()['local'];
    });

    expect($output)->toBe('GL')
        ->and($context->scope->all())->toBe(['global' => 'G'])
        ->and($context->state->section('head'))->toBe('H');
});

it('pops a render frame after an exception', function (): void {
    $context = new RenderContext(['global' => 'G']);

    expect(fn(): string => $context->renderFrame(['local' => 'L'], static function (): string {
        throw new RuntimeException('stop');
    }))->toThrow(RuntimeException::class, 'stop')
        ->and($context->scope->all())->toBe(['global' => 'G']);
});
