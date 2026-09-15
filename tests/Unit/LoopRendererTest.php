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

it('builds one metadata key set for items and counters', function (): void {
    $frames = [];
    $renderer = new LoopRenderer(function (array $scope) use (&$frames): string {
        $frames[] = $scope;

        return '';
    });

    $renderer->renderItems(['a', 'b'], []);

    $itemKeys = array_keys($frames[0]);

    $frames = [];
    $renderer->renderCounter(1, 2, []);

    expect($itemKeys)->toBe([
        'count', 'index', 'total', 'total_results', 'no_results', 'first',
        'last', 'odd', 'even', 'key', 'prev', 'next', 'value',
    ])->and(array_keys($frames[0]))->toBe($itemKeys);
});

it('gives counter loops the key, prev and next of the items path', function (): void {
    $frames = [];
    $renderer = new LoopRenderer(function (array $scope) use (&$frames): string {
        $frames[] = $scope;

        return '';
    });

    $renderer->renderCounter(3, 1, []);

    expect($frames[0]['key'])->toBe(0)
        ->and($frames[0]['prev'])->toBeNull()
        ->and($frames[0]['next'])->toBe(['value' => 2])
        ->and($frames[1]['key'])->toBe(1)
        ->and($frames[1]['prev'])->toBe(['value' => 3])
        ->and($frames[1]['next'])->toBe(['value' => 1])
        ->and($frames[2]['key'])->toBe(2)
        ->and($frames[2]['prev'])->toBe(['value' => 2])
        ->and($frames[2]['next'])->toBeNull();
});

it('reports total_results, total and no_results from both producers', function (): void {
    $frames = [];
    $renderer = new LoopRenderer(function (array $scope) use (&$frames): string {
        $frames[] = $scope;

        return '';
    });

    $renderer->renderItems(['a', 'b', 'c'], []);
    $renderer->renderCounter(1, 3, []);

    foreach ($frames as $frame) {
        expect($frame['total_results'])->toBe(3)
            ->and($frame['total'])->toBe(3)
            ->and($frame['no_results'])->toBeFalse();
    }
});
