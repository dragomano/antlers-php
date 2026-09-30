<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;

function renderFileFixture(string $name): string
{
    return __DIR__ . '/../Fixtures/RenderFile/' . $name;
}

function renderViewFixture(string $name): string
{
    return __DIR__ . '/../Fixtures/RenderView/' . $name;
}

it('renders a file with data', function (): void {
    expect(engine()->renderFile(renderFileFixture('name.antlers.html'), ['name' => 'Alice']))->toBe('Alice');
});

it('throws when file does not exist', function (): void {
    expect(fn(): string => engine()->renderFile('/tmp/does_not_exist_antlers_xyz.html'))
        ->toThrow(AntlersRuntimeException::class, 'Template file not found');
});

it('renders an empty file as empty string', function (): void {
    expect(engine()->renderFile(renderFileFixture('empty.antlers.html')))->toBe('');
});

it('renders conditions from a file', function (): void {
    expect(engine()->renderFile(renderFileFixture('condition.antlers.html'), ['active' => true]))->toBe('yes');
});

it('applies modifiers from a file', function (): void {
    expect(engine()->renderFile(renderFileFixture('modifier.antlers.html'), ['title' => 'hello']))->toBe('HELLO');
});

it('uses global variables in a file', function (): void {
    $e = engine();
    $e->addGlobal('site', 'MySite');

    expect($e->renderFile(renderFileFixture('site.antlers.html')))->toBe('MySite');
});

it('local data overrides globals in a file', function (): void {
    $e = engine();
    $e->addGlobal('name', 'Global');

    expect($e->renderFile(renderFileFixture('name.antlers.html'), ['name' => 'Local']))->toBe('Local');
});

it('blocks renderFile outside configured template roots', function (): void {
    $e = engine();
    $e->setViewPaths(renderFileFixture(''));

    expect(fn(): string => $e->renderFile(__DIR__ . '/../Fixtures/CoreTags/outside.antlers.html'))
        ->toThrow(AntlersRuntimeException::class, 'outside the configured template roots');
});

it('does not read the working directory as a template root', function (): void {
    // The file is right there, one `getcwd()` away: nothing may reach it while
    // no template root is configured.
    expect(is_file('composer.json'))->toBeTrue();

    expect(engine()->render('[{{ partial src="composer.json" }}]'))->toBe('[]')
        ->and(engine()->render('[{{ svg src="composer.json" }}]'))->toBe('[]')
        ->and(engine()->render('[{{ layout src="composer.json" }}]'))->toBe('[]')
        ->and(fn(): string => engine()->setStrictMode(true)->render('{{ partial src="composer.json" }}'))
        ->toThrow(AntlersRuntimeException::class, 'Partial not found: "composer.json"')
        ->and(fn(): string => engine()->setStrictMode(true)->render('{{ layout src="composer.json" }}'))
        ->toThrow(AntlersRuntimeException::class, 'Layout not found: "composer.json"');
});

it('keeps a rendered file and its partials inside the file directory', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antlers-entry-' . bin2hex(random_bytes(4));
    mkdir($root . DIRECTORY_SEPARATOR . 'partials', recursive: true);
    $page   = $root . DIRECTORY_SEPARATOR . 'page.html';
    $escape = $root . DIRECTORY_SEPARATOR . 'escape.html';
    $card   = $root . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'card.html';
    $secret = dirname($root) . DIRECTORY_SEPARATOR . basename($root) . '-secret.html';
    file_put_contents($page, 'page[{{ partial src="partials/card.html" }}]');
    file_put_contents($escape, '[{{ partial src="../' . basename($secret) . '" }}]');
    file_put_contents($card, 'CARD');
    file_put_contents($secret, 'SECRET');

    try {
        $e = engine();

        expect($e->renderFile($page))->toBe('page[CARD]')
            ->and($e->renderFile($escape))->toBe('[]')
            // A later top-level render may name another directory: the adopted
            // root lives as long as the render, so nothing is carried over.
            ->and($e->renderFile($card))->toBe('CARD');
    } finally {
        unlink($page);
        unlink($escape);
        unlink($card);
        unlink($secret);
        rmdir($root . DIRECTORY_SEPARATOR . 'partials');
        rmdir($root);
    }
});

it('blocks a relative entry path when no view paths are configured', function (): void {
    expect(fn(): string => engine()->renderFile('page.html'))
        ->toThrow(AntlersRuntimeException::class, 'outside the configured template roots');
});

it('renders a view by name from configured view paths', function (): void {
    $e = engine();
    $e->setViewPaths(renderViewFixture('views'));

    expect(rtrim($e->renderView('pages/home', ['title' => 'Welcome'])))->toBe('Home: Welcome');
});

it('supports extensionless lookup and layout rendering for views', function (): void {
    $e = engine();
    $e->setViewPaths(renderViewFixture('views'));

    expect(rtrim($e->renderView('pages/about', ['title' => 'About'])))->toBe('<body><h1>About</h1></body>');
});

it('falls back across multiple configured view paths', function (): void {
    $e = engine();
    $e->setViewPaths([
        renderViewFixture('fallback-a'),
        renderViewFixture('fallback-b'),
    ]);

    expect(rtrim($e->renderView('shared/message', ['name' => 'Alice'])))->toBe('Hello, Alice!');
});

it('blocks renderView path traversal outside configured view paths', function (): void {
    $e = engine();
    $e->setViewPaths(renderViewFixture('views/pages'));

    expect(fn(): string => $e->renderView('../main'))
        ->toThrow(AntlersRuntimeException::class, 'Template view not found');
});

it('throws on recursive view rendering', function (): void {
    $e = engine();
    $e->setViewPaths(renderViewFixture('views'));

    expect(fn(): string => $e->renderView('recursive/main'))
        ->toThrow(AntlersRuntimeException::class, 'Recursive template rendering detected');
});
