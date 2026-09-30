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

it('resolves nothing while no template root exists', function (): void {
    $locator = new TemplateLocator();

    expect($locator->resolveTemplatePath('composer.json'))->toBe('')
        ->and($locator->resolveTemplatePath(__FILE__))->toBe('')
        ->and($locator->resolveTemplateTagPath('card.antlers.html'))->toBe('')
        ->and($locator->resolveViewPath('card'))->toBe('')
        ->and($locator->resolveEntryTemplatePath(''))->toBe('')
        ->and($locator->resolveEntryTemplatePath('card.antlers.html'))->toBe('');
});

it('adopts the entry file directory as the root of that render', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antlers-entry-' . bin2hex(random_bytes(4));
    mkdir($root . DIRECTORY_SEPARATOR . 'partials', recursive: true);
    $root  = realpath($root);
    $page  = $root . DIRECTORY_SEPARATOR . 'page.html';
    $card  = $root . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'card.html';
    $other = dirname($root) . DIRECTORY_SEPARATOR . basename($root) . '-secret.html';
    file_put_contents($page, 'page');
    file_put_contents($card, 'card');
    file_put_contents($other, 'secret');

    try {
        $locator = new TemplateLocator();
        $entry   = $locator->resolveEntryTemplatePath($page);
        $locator->pushTemplate($entry);

        expect($entry)->toBe($page)
            ->and($locator->resolveTemplateTagPath('partials/card.html'))->toBe($card)
            ->and($locator->resolveTemplatePath('partials/card.html'))->toBe($card)
            ->and($locator->resolveTemplatePath($card))->toBe($card)
            ->and($locator->resolveTemplateTagPath('../' . basename($other)))->toBe('')
            ->and($locator->resolveTemplatePath($other))->toBe('')
            ->and($locator->resolveTemplateTagPath($card))->toBe('')
            ->and($locator->resolveEntryTemplatePath('/../../etc/passwd'))->toBe('');
    } finally {
        unlink($page);
        unlink($card);
        unlink($other);
        rmdir($root . DIRECTORY_SEPARATOR . 'partials');
        rmdir($root);
    }
});

it('holds the entry path to configured view paths', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antlers-entry-roots-' . bin2hex(random_bytes(4));
    mkdir($root);
    $root = realpath($root);
    $page = $root . DIRECTORY_SEPARATOR . 'page.html';
    file_put_contents($page, 'page');

    try {
        $locator = new TemplateLocator();
        $locator->setViewPaths($root);

        expect($locator->resolveEntryTemplatePath($page))->toBe($page)
            ->and($locator->resolveEntryTemplatePath(''))->toBe('')
            ->and($locator->resolveEntryTemplatePath('../page.html'))->toBe('')
            ->and($locator->resolveEntryTemplatePath($root . '/../' . basename($root) . '-secret.html'))->toBe('')
            // A missing file inside a root still resolves, so the "not found" error can name it.
            ->and($locator->resolveEntryTemplatePath($root . DIRECTORY_SEPARATOR . 'missing.html'))
            ->toBe($root . DIRECTORY_SEPARATOR . 'missing.html')
            ->and($locator->resolveEntryTemplatePath($page))->toBe($page);
    } finally {
        unlink($page);
        rmdir($root);
    }
});
