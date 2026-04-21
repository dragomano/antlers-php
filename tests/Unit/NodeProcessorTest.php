<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;
use Bugo\Antlers\Modifiers\CoreModifiers;
use Bugo\Antlers\Modifiers\ModifierRegistry;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Nodes\AssignmentNode;
use Bugo\Antlers\Nodes\BooleanNode;
use Bugo\Antlers\Nodes\LiteralNode;
use Bugo\Antlers\Nodes\LoopNode;
use Bugo\Antlers\Nodes\NumberNode;
use Bugo\Antlers\Nodes\SequenceNode;
use Bugo\Antlers\Nodes\SetNode;
use Bugo\Antlers\Nodes\StringValueNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Parser\DocumentParser;
use Bugo\Antlers\Parser\LanguageParser;
use Bugo\Antlers\Runtime\ConditionProcessor;
use Bugo\Antlers\Runtime\ExpressionEvaluator;
use Bugo\Antlers\Runtime\ModifierRunner;
use Bugo\Antlers\Runtime\NodeProcessor;
use Bugo\Antlers\Runtime\PathDataManager;
use Bugo\Antlers\Runtime\RuntimeOptions;
use Bugo\Antlers\Tags\CoreTags;
use Bugo\Antlers\Tags\TagRegistry;

function nodeProcessor(bool $strict = false, ?GuardPolicy $guardPolicy = null): NodeProcessor
{
    $documentParser = new DocumentParser();
    $languageParser = new LanguageParser();
    $tagRegistry    = new TagRegistry();
    $modifiers      = new ModifierRegistry();

    CoreTags::register($tagRegistry);
    CoreModifiers::register($modifiers);

    $options              = new RuntimeOptions();
    $options->strict      = $strict;
    $options->guardPolicy = $guardPolicy ?? new GuardPolicy();

    $paths      = new PathDataManager();
    $runner     = new ModifierRunner($modifiers, $options);
    $evaluator  = new ExpressionEvaluator($paths, $runner, $options);
    $conditions = new ConditionProcessor($evaluator);

    return new NodeProcessor(
        $documentParser,
        $evaluator,
        $conditions,
        $tagRegistry,
        $paths,
        $languageParser,
        $options,
    );
}

function nodeProcessorWithTag(string $name, callable $handler): NodeProcessor
{
    $documentParser = new DocumentParser();
    $languageParser = new LanguageParser();
    $tagRegistry    = new TagRegistry();
    $modifiers      = new ModifierRegistry();

    CoreTags::register($tagRegistry);
    CoreModifiers::register($modifiers);
    $tagRegistry->register($name, $handler);

    $options    = new RuntimeOptions();
    $paths      = new PathDataManager();
    $runner     = new ModifierRunner($modifiers, $options);
    $evaluator  = new ExpressionEvaluator($paths, $runner, $options);
    $conditions = new ConditionProcessor($evaluator);

    return new NodeProcessor(
        $documentParser,
        $evaluator,
        $conditions,
        $tagRegistry,
        $paths,
        $languageParser,
        $options,
    );
}

function renderFileFixturePath(string $name): string
{
    return dirname(__DIR__) . '/Fixtures/RenderFile/' . $name;
}

function renderViewFixturePath(string $name): string
{
    return dirname(__DIR__) . '/Fixtures/RenderView/' . $name;
}

function normalizePathSeparators(string $path): string
{
    return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
}

it('processes set, sequence, raw antlers and loop node edge cases', function (): void {
    $processor = nodeProcessor();

    $closing               = new AntlersNode();
    $closing->isClosingTag = true;
    $closing->name         = 'if';

    $pairedRaw             = new AntlersNode();
    $pairedRaw->rawContent = 'items | upper';
    $pairedRaw->name       = 'items';
    $pairedRaw->children   = [new LiteralNode('X')];

    expect($processor->reduce([
        new SetNode('name', new StringValueNode('Alice')),
        new VariableNode('name'),
    ]))->toBe('Alice')
        ->and($processor->reduce([
            new SequenceNode([
                new AssignmentNode('name', new StringValueNode('Bob')),
            ]),
        ]))->toBe('')
        ->and($processor->reduce([$closing]))->toBe('')
        ->and($processor->reduce([$pairedRaw], ['items' => ['A', 'B']]))->toBe('')
        ->and($processor->reduce([
            new LoopNode('paired', variablePath: 'items', children: [new VariableNode('value')]),
        ], ['items' => [1, 2]]))->toBe('12')
        ->and($processor->reduce([new LoopNode('unknown')]))->toBe('')
        ->and($processor->reduce([new LoopNode('foreach')]))->toBe('')
        ->and($processor->reduce([new LoopNode('foreach', new NumberNode(1))]))->toBe('')
        ->and($processor->reduce([new LoopNode('for')]))->toBe('');
});

it('handles paired values, null tag results and raw noparse children', function (): void {
    $processor            = nodeProcessor();
    $processorWithNullTag = nodeProcessorWithTag('nuller', fn(): mixed => null);

    $nullTag              = new TagNode('nuller');
    $noparse              = new AntlersNode();
    $noparse->name        = 'noparse';
    $rawChild             = new AntlersNode();
    $rawChild->rawContent = 'name';
    $rawChild->name       = 'name';
    $noparse->children    = [
        new LiteralNode('Hi '),
        $rawChild,
    ];

    expect($processor->renderTemplate('{{ item }}{{ name }}{{ /item }}', ['item' => ['name' => 'Alice']]))->toBe('Alice')
        ->and($processor->renderTemplate('{{ thing }}{{ title }}{{ /thing }}', ['thing' => (object) ['title' => 'Book']]))->toBe('Book')
        ->and($processor->renderTemplate('{{ thing }}X{{ /thing }}', ['thing' => 'yes']))->toBe('X')
        ->and($processor->renderTemplate('{{ thing }}X{{ /thing }}', ['thing' => false]))->toBe('')
        ->and($processor->renderIterable('nope', [new LiteralNode('X')]))->toBe('')
        ->and($processorWithNullTag->reduce([$nullTag]))->toBe('')
        ->and($processor->reduce([$noparse]))->toBe('Hi {{name}}');
});

it('supports sections, stacks, slots, once and helper loops', function (): void {
    $processor = nodeProcessor();

    $slotTag = new TagNode(
        'slot',
        'index',
        ['name' => new StringValueNode('sidebar')],
        [new LiteralNode('Inner')],
        true,
    );

    $processor->storeSection('hero', 'A');
    $processor->storeSection('hero', 'B', true);
    $processor->storeStack('scripts', 'A');
    $processor->storeStack('scripts', 'B', true);

    expect($processor->yieldSection('missing'))->toBe('')
        ->and($processor->yieldSection('hero'))->toBe('AB')
        ->and($processor->yieldStack('scripts'))->toBe('BA')
        ->and($processor->renderSlots([$slotTag], []))->toBe([
            'default' => '',
            'named'   => ['sidebar' => 'Inner'],
        ])
        ->and($processor->renderSlots([
            new TagNode('slot', 'index', [], [new LiteralNode('Fallback')], true),
        ], []))->toBe([
            'default' => '',
            'named'   => ['default' => 'Fallback'],
        ])
        ->and($processor->renderOnce(null, fn(): string => 'A'))->toBe('A')
        ->and($processor->renderOnce(null, fn(): string => 'B'))->toBe('B')
        ->and($processor->renderCounterLoop(2, 1, [new VariableNode('value')]))->toBe('21')
        ->and($processor->nextSwitchValue('rows', ['a', 'b']))->toBe('a')
        ->and($processor->nextSwitchValue('rows', ['a', 'b']))->toBe('b')
        ->and($processor->nextIncrement('row', 10, 5))->toBe(10)
        ->and($processor->nextIncrement('row', 10, 5))->toBe(15);
});

it('resolves template and tag paths across view roots and malformed inputs', function (): void {
    $processor = nodeProcessor();
    $processor->setViewPaths(renderViewFixturePath('views'));

    $viewRoot = renderViewFixturePath('views');

    expect($processor->resolveTemplatePath(''))->toBe('')
        ->and($processor->resolveTemplatePath('pages/home.antlers.html'))->toBe(realpath($viewRoot . '/pages/home.antlers.html'))
        ->and($processor->resolveTemplatePath('pages/missing.antlers.html'))
        ->toBe(normalizePathSeparators($viewRoot . '/pages/missing.antlers.html'))
        ->and($processor->resolveTemplatePath('/../../etc/passwd'))->toBe('')
        ->and($processor->resolveTemplatePath($viewRoot . '/./pages//home.antlers.html'))->toBe(realpath($viewRoot . '/pages/home.antlers.html'))
        ->and($processor->resolveTemplateTagPath(''))->toBe('')
        ->and($processor->resolveTemplateTagPath('/tmp/test'))->toBe('')
        ->and($processor->resolveTemplateTagPath(str_repeat('../', 20) . 'etc/passwd'))->toBe('')
        ->and($processor->resolveTemplateTagPath('../../../etc/passwd'))->toBe('');
});

it('handles render file and view fallbacks, globals and strict path resolution', function (): void {
    $processor = nodeProcessor();
    $processor->setGlobalData(['site' => 'Docs']);

    expect($processor->renderTemplate('{{ site }}'))->toBe('Docs')
        ->and(rtrim($processor->renderTemplateFile(renderFileFixturePath('once-file.antlers.html'))))->toBe('A')
        ->and($processor->resolveTemplatePath('composer.json'))->toBe(getcwd() . DIRECTORY_SEPARATOR . 'composer.json')
        ->and($processor->resolveTemplatePath('missing.file'))->toBe(getcwd() . DIRECTORY_SEPARATOR . 'missing.file')
        ->and(fn(): string => $processor->renderView(''))->toThrow(AntlersRuntimeException::class, 'Template view not found')
        ->and(fn(): mixed => nodeProcessor(strict: true)->resolvePathValue('missing', []))
        ->toThrow(AntlersRuntimeException::class, 'Undefined variable: "missing"');

    $withViews = nodeProcessor();
    $withViews->setViewPaths(renderViewFixturePath('views'));

    expect($withViews->renderView('pages/home', ['title' => 'Welcome']))->toBe("Home: Welcome\n");
    expect($withViews->resolveTemplatePath('C:\\templates\\home.antlers.html'))->toBe('');

    expect(fn(): string => nodeProcessor()->renderView('missing-view'))
        ->toThrow(AntlersRuntimeException::class, 'Template view not found');
});

it('normalizes raw numeric inputs through for loops', function (): void {
    $processor = nodeProcessor();

    expect($processor->reduce([
        new LoopNode(
            'for',
            from: new StringValueNode('2'),
            to: new NumberNode(3.8),
            children: [new VariableNode('value')],
        ),
    ]))->toBe('23')
        ->and($processor->reduce([
            new LoopNode(
                'for',
                from: new BooleanNode(false),
                to: new BooleanNode(true),
                children: [new VariableNode('value')],
            ),
        ]))->toBe('01')
        ->and($processor->reduce([
            new LoopNode(
                'for',
                from: new StringValueNode('abc'),
                to: new StringValueNode('abc'),
                children: [new VariableNode('value')],
            ),
        ]))->toBe('0');
});
