<?php

declare(strict_types=1);

use Bugo\Antlers\Modifiers\CoreModifiers;
use Bugo\Antlers\Modifiers\ModifierRegistry;
use Bugo\Antlers\Nodes\LiteralNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Parser\LanguageParser;
use Bugo\Antlers\Runtime\ExpressionEvaluator;
use Bugo\Antlers\Runtime\ModifierRunner;
use Bugo\Antlers\Runtime\PathDataManager;
use Bugo\Antlers\Runtime\RuntimeOptions;
use Bugo\Antlers\Runtime\SlotRenderer;
use Bugo\Antlers\Tags\NameResolver;
use Bugo\Antlers\Tags\TagRegistry;

it('separates default and named slot content', function (): void {
    $options   = new RuntimeOptions();
    $modifiers = new ModifierRegistry();

    CoreModifiers::register($modifiers, $options);

    $tags      = new TagRegistry();
    $paths     = new PathDataManager();
    $evaluator = new ExpressionEvaluator($paths, new ModifierRunner($modifiers, $options), $options);

    $render = static fn(array $nodes): string => implode('', array_map(
        static fn(object $node): string => $node instanceof LiteralNode ? $node->content : '',
        $nodes,
    ));

    $renderer = new SlotRenderer(
        new LanguageParser(new NameResolver($tags)),
        $evaluator,
        $render,
        static fn(): null => null,
    );

    $slot = new TagNode('slot', 'hero', [], [new LiteralNode('H')], true);

    expect($renderer->render([new LiteralNode('D'), $slot]))->toBe([
        'default' => 'D',
        'named'   => ['hero' => 'H'],
    ]);
});
