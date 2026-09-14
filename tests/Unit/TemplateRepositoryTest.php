<?php

declare(strict_types=1);

use Bugo\Antlers\Nodes\LiteralNode;
use Bugo\Antlers\Parser\DocumentParser;
use Bugo\Antlers\Runtime\TemplateLocator;
use Bugo\Antlers\Runtime\TemplateRepository;

it('loads parses and renders a template file', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antlers-repository-' . bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'page.antlers.html', 'Hello');

    try {
        $locator = new TemplateLocator();
        $locator->setViewPaths($root);
        $repository = new TemplateRepository(new DocumentParser(), $locator);

        $result = $repository->renderView('page', ['name' => 'A'], static function (array $nodes, array $data): string {
            expect($data)->toBe(['name' => 'A']);

            return implode('', array_map(
                static fn(object $node): string => $node instanceof LiteralNode ? $node->content : '',
                $nodes,
            ));
        });

        expect($result)->toBe('Hello');
    } finally {
        unlink($root . DIRECTORY_SEPARATOR . 'page.antlers.html');
        rmdir($root);
    }
});

it('reuses parsed file nodes until the template changes', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antlers-cache-' . bin2hex(random_bytes(4));
    mkdir($root);
    $path = $root . DIRECTORY_SEPARATOR . 'page.antlers.html';
    file_put_contents($path, 'First');

    try {
        $locator = new TemplateLocator();
        $locator->setViewPaths($root);
        $repository = new TemplateRepository(new DocumentParser(), $locator);
        $nodeIds = [];

        $render = static function (array $nodes) use (&$nodeIds): string {
            $nodeIds[] = spl_object_id($nodes[0]);

            return $nodes[0] instanceof LiteralNode ? $nodes[0]->content : '';
        };

        expect($repository->renderView('page', [], $render))->toBe('First')
            ->and($repository->renderView('page', [], $render))->toBe('First')
            ->and($nodeIds[1])->toBe($nodeIds[0]);

        file_put_contents($path, 'Second version');
        touch($path, time() + 2);

        expect($repository->renderView('page', [], $render))->toBe('Second version')
            ->and($nodeIds[2])->not->toBe($nodeIds[0]);
    } finally {
        unlink($path);
        rmdir($root);
    }
});
