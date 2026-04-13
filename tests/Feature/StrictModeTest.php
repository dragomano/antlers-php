<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;
use Bugo\Antlers\Exceptions\AntlersRuntimeException;

function strictEngine(): Engine
{
    return engine()->setStrictMode(true);
}

it('throws on undefined variable in strict mode', function (): void {
    expect(fn(): string => strictEngine()->render('{{ missing }}'))
        ->toThrow(AntlersRuntimeException::class, 'Undefined variable: "missing"');
});

it('throws on undefined nested path in strict mode', function (): void {
    expect(fn(): string => strictEngine()->render('{{ user.name }}'))
        ->toThrow(AntlersRuntimeException::class, 'Undefined variable: "user.name"');
});

it('renders defined variable normally in strict mode', function (): void {
    expect(strictEngine()->render('{{ name }}', ['name' => 'Alice']))->toBe('Alice');
});

it('does not throw when variable value is null in strict mode', function (): void {
    // Variable IS defined, value just happens to be null → empty string, no exception
    expect(strictEngine()->render('{{ name }}', ['name' => null]))->toBe('');
});

it('does not throw for undefined left side of ?? in strict mode', function (): void {
    expect(strictEngine()->render('{{ missing ?? "default" }}'))->toBe('default');
});

it('returns defined right side variable via ?? in strict mode', function (): void {
    expect(strictEngine()->render('{{ missing ?? name }}', ['name' => 'Bob']))->toBe('Bob');
});

it('does not evaluate the gatekeeper right side when the left side is falsy in strict mode', function (): void {
    expect(strictEngine()->render('{{ show_bio ?= missing }}', ['show_bio' => false]))->toBe('');
});

it('throws when modifier is applied to undefined variable in strict mode', function (): void {
    expect(fn(): string => strictEngine()->render('{{ missing | upper }}'))
        ->toThrow(AntlersRuntimeException::class, 'Undefined variable: "missing"');
});

// A simple {{ name }} without parameters is treated as a variable lookup.
// An explicit tag call with parameters (TagNode) triggers the tag registry check.
it('throws on unknown tag with parameters in strict mode', function (): void {
    expect(fn(): string => strictEngine()->render('{{ unknownTag param="value" }}'))
        ->toThrow(AntlersRuntimeException::class, 'Unknown tag: "unknownTag"');
});

it('returns empty string for undefined variable in lenient mode', function (): void {
    expect(engine()->render('{{ missing }}'))->toBe('');
});

it('returns empty string for unknown tag in lenient mode', function (): void {
    expect(engine()->render('{{ unknownTag }}'))->toBe('');
});

it('can disable strict mode after enabling it', function (): void {
    $e = strictEngine()->setStrictMode(false);
    expect($e->render('{{ missing }}'))->toBe('');
});
