<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\LoopRenderer;

it('renders collection metadata and aliases', function (): void {
    $frames = [];
    $renderer = new LoopRenderer(function (array $scope) use (&$frames): string {
        $frames[] = $scope;

        return (string) $scope['item'];
    });

    expect($renderer->renderItems(['a' => 10, 'b' => 20], [], 'item', 'itemKey'))->toBe('1020')
        ->and($frames[0]['first'])->toBeTrue()
        ->and($frames[0]['next'])->toBe(['value' => 20])
        ->and($frames[1]['last'])->toBeTrue()
        ->and($frames[1]['prev'])->toBe(['value' => 10])
        ->and($frames[1]['itemKey'])->toBe('b');
});

it('renders descending counter ranges', function (): void {
    $values = [];
    $renderer = new LoopRenderer(function (array $scope) use (&$values): string {
        $values[] = $scope;

        return (string) $scope['value'];
    });

    expect($renderer->renderCounter(3, 1, []))->toBe('321')
        ->and($values[0]['total'])->toBe(3)
        ->and($values[2]['last'])->toBeTrue();
});
