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

it('does not throw for non-variable expression on left side of ?? in strict mode', function (): void {
    expect(strictEngine()->render('{{ (missing + 1) ?? "default" }}'))->toBe('1');
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

it('throws when a modifier argument references an undefined variable in strict mode', function (): void {
    expect(fn(): string => strictEngine()->render('{{ name | truncate:limit }}', ['name' => 'Alice']))
        ->toThrow(AntlersRuntimeException::class, 'Undefined variable: "limit"');
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

it('returns empty string for unknown tag with parameters in lenient mode', function (): void {
    expect(engine()->render('{{ unknownTag param="value" }}'))->toBe('');
});

it('returns the original value for an unknown modifier in lenient mode', function (): void {
    expect(engine()->render('{{ name | missing_modifier }}', ['name' => 'Alice']))->toBe('Alice');
});

it('throws for an unknown modifier in strict mode', function (): void {
    expect(fn(): string => strictEngine()->render('{{ name | missing_modifier }}', ['name' => 'Alice']))
        ->toThrow(AntlersRuntimeException::class, 'Unknown modifier: "missing_modifier"');
});

it('throws for a scope tag without a name in strict mode', function (): void {
    expect(engine()->render('{{ scope }}{{ title }}{{ /scope }}', ['title' => 'Home']))->toBe('');

    expect(fn(): string => strictEngine()->render('{{ scope }}{{ title }}{{ /scope }}', ['title' => 'Home']))
        ->toThrow(AntlersRuntimeException::class, 'Scope tag requires a name.');
});

it('throws for an svg tag without a src in strict mode', function (): void {
    expect(engine()->render('{{ svg }}'))->toBe('');

    expect(fn(): string => strictEngine()->render('{{ svg }}'))
        ->toThrow(AntlersRuntimeException::class, 'Svg tag requires a "src" parameter.');
});

it('keeps the subject and reports a failed regex_replace', function (): void {
    // Backtrack limit rather than a bad pattern: same branch, no PHP warning
    $tpl     = '{{ text | regex_replace:"/^(a+)+$/":"y" }}';
    $subject = str_repeat('a', 30) . 'b';

    // A failed pattern must never silently blank the value out
    expect(engine()->render($tpl, ['text' => $subject]))->toBe($subject);

    expect(fn(): string => strictEngine()->render($tpl, ['text' => $subject]))
        ->toThrow(AntlersRuntimeException::class, 'regex_replace failed for pattern "/^(a+)+$/"');
});

it('can disable strict mode after enabling it', function (): void {
    $e = strictEngine()->setStrictMode(false);
    expect($e->render('{{ missing }}'))->toBe('');
});
