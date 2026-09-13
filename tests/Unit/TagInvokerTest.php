<?php

declare(strict_types=1);

use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Runtime\RuntimeOptions;
use Bugo\Antlers\Runtime\TagInvoker;
use Bugo\Antlers\Tags\TagRegistry;

it('tracks and clears the current tag context', function (): void {
    $registry = new TagRegistry();
    $registry->register('sample', static fn(): string => 'unused');

    $invoker = null;
    $invoker = new TagInvoker(
        $registry,
        new RuntimeOptions(),
        static fn(): null => null,
        function (string $name, string $method) use (&$invoker): string {
            expect($invoker->currentContext()['name'])->toBe($name)
                ->and($invoker->currentContext()['method'])->toBe($method);

            return 'ok';
        },
    );

    expect($invoker->call(new TagNode('sample'), []))->toBe('ok')
        ->and($invoker->currentContext())->toBeNull();
});
