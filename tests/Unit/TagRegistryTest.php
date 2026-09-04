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
        new LanguageParser(),
        $options,
    );
}

it('reports registered tags', function (): void {
    $registry = new TagRegistry();
    $registry->register('greeting', static fn(): string => 'hi');

    expect($registry->has('greeting'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse();
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
