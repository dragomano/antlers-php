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
