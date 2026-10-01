<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Modifiers\ModifierRegistry;
use Bugo\Antlers\Parser\DocumentParser;
use Bugo\Antlers\Parser\LanguageParser;
use Bugo\Antlers\Runtime\ConditionProcessor;
use Bugo\Antlers\Runtime\ExpressionEvaluator;
use Bugo\Antlers\Runtime\ModifierRunner;
use Bugo\Antlers\Runtime\NodeProcessor;
use Bugo\Antlers\Runtime\PathDataManager;
use Bugo\Antlers\Runtime\RuntimeOptions;
use Bugo\Antlers\Tags\NameResolver;
use Bugo\Antlers\Tags\TagRegistry;

function bareNodeProcessor(TagRegistry $tagRegistry): NodeProcessor
{
    $options   = new RuntimeOptions();
    $paths     = new PathDataManager();
    $runner    = new ModifierRunner(new ModifierRegistry(), $options);
    $evaluator = new ExpressionEvaluator($paths, $runner, $options);

    return new NodeProcessor(
        new DocumentParser(),
        $evaluator,
        new ConditionProcessor($evaluator),
        $tagRegistry,
        $paths,
        new LanguageParser(new NameResolver($tagRegistry)),
        $options,
    );
}

it('reports registered tags', function (): void {
    $registry = new TagRegistry();
    $registry->register('greeting', static fn(): string => 'hi');

    expect($registry->has('greeting'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse();
});

it('normalises tag names to lowercase on register and lookup', function (): void {
    $registry = new TagRegistry();
    $registry->register('myBox', static fn(): string => 'ok');

    expect($registry->has('myBox'))->toBeTrue()
        ->and($registry->has('MYBOX'))->toBeTrue()
        ->and($registry->handle('MYBOX', 'index', [], [], bareNodeProcessor($registry)))->toBe('ok');
});

it('keeps one tag per name across registration casings', function (): void {
    $registry = new TagRegistry();
    $registry->register('myBox', static fn(): string => 'first');
    $registry->register('mybox', static fn(): string => 'second');

    expect($registry->handle('myBox', 'index', [], [], bareNodeProcessor($registry)))->toBe('second');
});

it('throws a runtime exception when handling an unregistered tag', function (): void {
    $registry = new TagRegistry();

    expect(fn(): mixed => $registry->handle('missing', 'index', [], [], bareNodeProcessor($registry)))
        ->toThrow(AntlersRuntimeException::class, 'Unknown tag: "missing"');
});

it('throws a runtime exception when applying an unregistered modifier', function (): void {
    // The lenient/strict decision belongs to ModifierRunner; reaching the
    // registry with an unknown name is a contract violation.
    expect(fn(): mixed => (new ModifierRegistry())->apply('missing', 'value', [], []))
        ->toThrow(AntlersRuntimeException::class, 'Unknown modifier: "missing"');
});
