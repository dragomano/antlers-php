<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Tags\AbstractTag;
use Bugo\Antlers\Tags\CoreTags;
use Bugo\Antlers\Tags\TagContext;
use Bugo\Antlers\Tags\TagRegistry;
use LogicException;

it('renders a paired variable through the public runtime helper', function (): void {
    expect(nodeProcessor()->processPairedVariable(
        'items',
        [new VariableNode('value')],
        ['items' => [1, 2]],
    ))->toBe('12');
});

it('rejects unknown names in a core tag group', function (): void {
    CoreTags::registerNames(new TagRegistry(), ['unknown']);
})->throws(LogicException::class, 'Unknown core tag: "unknown"');

it('handles a missing public method on a class tag', function (): void {
    $engine = new Engine();
    $engine->addTag('probe', new class extends AbstractTag {
        public function index(TagContext $context): string
        {
            return 'index';
        }
    });

    expect($engine->render('{{ probe:missing }}'))->toBe('');
});
