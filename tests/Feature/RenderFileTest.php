<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;

function renderFileFixture(string $name): string
{
    return dirname(__DIR__) . '/Fixtures/RenderFile/' . $name;
}

function renderViewFixture(string $name): string
{
    return dirname(__DIR__) . '/Fixtures/RenderView/' . $name;
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

    expect(fn(): string => $e->renderFile(dirname(__DIR__) . '/Fixtures/CoreTags/outside.antlers.html'))
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
