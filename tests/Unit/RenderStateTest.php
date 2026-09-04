<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\RenderState;
use Bugo\Antlers\Runtime\Scope;

describe('Scope', function (): void {
    it('layers frames over globals', function (): void {
        $scope = new Scope(['site' => 'Blog', 'year' => 2026]);

        expect($scope->all())->toBe(['site' => 'Blog', 'year' => 2026]);

        $scope->push(['year' => 2027, 'title' => 'Post']);

        expect($scope->all())->toBe(['site' => 'Blog', 'year' => 2027, 'title' => 'Post']);

        $scope->pop();

        expect($scope->all())->toBe(['site' => 'Blog', 'year' => 2026]);
    });

    it('invalidates the flattened view on every mutation', function (): void {
        $scope = new Scope();
        $scope->push(['a' => 1]);

        expect($scope->all())->toBe(['a' => 1]);

        $scope->write('a', 2);

        expect($scope->all())->toBe(['a' => 2]);

        $scope->push(['b' => 3]);

        expect($scope->all())->toBe(['a' => 2, 'b' => 3]);

        $scope->pop();

        expect($scope->all())->toBe(['a' => 2]);
    });

    it('writes into the innermost frame', function (): void {
        $scope = new Scope();
        $scope->push(['a' => 1]);
        $scope->push([]);
        $scope->write('a', 2);

        expect($scope->all())->toBe(['a' => 2]);

        $scope->pop();

        expect($scope->all())->toBe(['a' => 1]);
    });

    it('opens a frame when writing with none pushed', function (): void {
        $scope = new Scope(['a' => 1]);
        $scope->write('a', 2);

        expect($scope->all())->toBe(['a' => 2]);
    });
});

describe('RenderState', function (): void {
    it('stores and appends sections', function (): void {
        $state = new RenderState();

        expect($state->section('head'))->toBe('');

        $state->storeSection('head', 'A');
        $state->storeSection('head', 'B', append: true);

        expect($state->section('head'))->toBe('AB');

        $state->storeSection('head', 'C');

        expect($state->section('head'))->toBe('C');
    });

    it('pushes and prepends stacks', function (): void {
        $state = new RenderState();

        expect($state->stack('js'))->toBe('');

        $state->pushStack('js', 'b');
        $state->pushStack('js', 'a', prepend: true);

        expect($state->stack('js'))->toBe('ab');
    });

    it('marks a once key only the first time', function (): void {
        $state = new RenderState();

        expect($state->onceCount())->toBe(0)
            ->and($state->markOnce('k'))->toBeTrue()
            ->and($state->markOnce('k'))->toBeFalse()
            ->and($state->onceCount())->toBe(1);
    });

    it('counts increments per name', function (): void {
        $state = new RenderState();

        expect($state->nextIncrement('a', 1, 1))->toBe(1)
            ->and($state->nextIncrement('a', 1, 1))->toBe(2)
            ->and($state->nextIncrement('b', 10, 5))->toBe(10)
            ->and($state->nextIncrement('b', 10, 5))->toBe(15);
    });

    it('advances switch indexes per name', function (): void {
        $state = new RenderState();

        expect($state->nextSwitchIndex('a'))->toBe(0)
            ->and($state->nextSwitchIndex('a'))->toBe(1)
            ->and($state->nextSwitchIndex('b'))->toBe(0);
    });
});
