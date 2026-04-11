<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;

it('renders a file with data', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'antlers_');
    file_put_contents($path, '{{ name }}');

    expect(engine()->renderFile($path, ['name' => 'Alice']))->toBe('Alice');

    unlink($path);
});

it('throws when file does not exist', function (): void {
    expect(fn(): string => engine()->renderFile('/tmp/does_not_exist_antlers_xyz.html'))
        ->toThrow(AntlersRuntimeException::class, 'Template file not found');
});

it('renders an empty file as empty string', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'antlers_');
    file_put_contents($path, '');

    expect(engine()->renderFile($path))->toBe('');

    unlink($path);
});

it('renders conditions from a file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'antlers_');
    file_put_contents($path, '{{ if active }}yes{{ else }}no{{ /if }}');

    expect(engine()->renderFile($path, ['active' => true]))->toBe('yes');

    unlink($path);
});

it('applies modifiers from a file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'antlers_');
    file_put_contents($path, '{{ title | upper }}');

    expect(engine()->renderFile($path, ['title' => 'hello']))->toBe('HELLO');

    unlink($path);
});

it('uses global variables in a file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'antlers_');
    file_put_contents($path, '{{ site }}');

    $e = engine();
    $e->addGlobal('site', 'MySite');

    expect($e->renderFile($path))->toBe('MySite');

    unlink($path);
});

it('local data overrides globals in a file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'antlers_');
    file_put_contents($path, '{{ name }}');

    $e = engine();
    $e->addGlobal('name', 'Global');

    expect($e->renderFile($path, ['name' => 'Local']))->toBe('Local');

    unlink($path);
});
