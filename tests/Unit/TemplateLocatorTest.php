<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\TemplateLocator;

it('resolves views within configured roots', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antlers-locator-' . bin2hex(random_bytes(4));
    mkdir($root);
    $root = realpath($root);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'card.antlers.html', 'card');

    try {
        $locator = new TemplateLocator();
        $locator->setViewPaths($root);

        expect($locator->resolveViewPath('card'))->toBe($root . DIRECTORY_SEPARATOR . 'card.antlers.html')
            ->and($locator->resolveTemplateTagPath('../outside'))->toBe('');
    } finally {
        unlink($root . DIRECTORY_SEPARATOR . 'card.antlers.html');
        rmdir($root);
    }
});

it('collapses redundant "." and empty path segments within a root', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antlers-normalize-' . bin2hex(random_bytes(4));
    mkdir($root);
    $root = realpath($root);
    $file = $root . DIRECTORY_SEPARATOR . 'card.antlers.html';
    file_put_contents($file, 'card');

    try {
        $locator = new TemplateLocator();
        $locator->setViewPaths($root);

        expect($locator->resolveTemplateTagPath('.//card.antlers.html'))->toBe($file);
    } finally {
        unlink($file);
        rmdir($root);
    }
});
