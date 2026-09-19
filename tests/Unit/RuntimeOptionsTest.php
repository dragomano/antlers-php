<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\RuntimeOptions;
use Bugo\Antlers\Support\MarkdownRendererInterface;

it('does not create the default markdown renderer until it is requested', function (): void {
    $options = new RuntimeOptions();
    $property = (new ReflectionClass($options))->getProperty('markdownRenderer');

    expect($property->getValue($options))->toBeNull()
        ->and($options->markdownRenderer())->toBeInstanceOf(MarkdownRendererInterface::class)
        ->and($property->getValue($options))->toBe($options->markdownRenderer());
});

it('allows a custom markdown renderer before the default is created', function (): void {
    $renderer = new class implements MarkdownRendererInterface {
        public function render(string $markdown): string
        {
            return $markdown;
        }
    };
    $options = new RuntimeOptions();

    $options->setMarkdownRenderer($renderer);

    expect($options->markdownRenderer())->toBe($renderer);
});
