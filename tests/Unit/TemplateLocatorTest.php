<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\TemplateLocator;

it('resolves views within configured roots', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antlers-locator-' . bin2hex(random_bytes(4));
    mkdir($root);
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
