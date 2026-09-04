<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersSyntaxException;

/*
 * Syntax errors have to tell a template author where to look, so every one of
 * them carries the template line and the offending fragment.
 */

function syntaxErrorFor(string $template): AntlersSyntaxException
{
    try {
        engine()->render($template);
    } catch (AntlersSyntaxException $e) {
        return $e;
    }

    throw new RuntimeException('Expected AntlersSyntaxException was not thrown.');
}

it('reports the template line of an expression error', function (): void {
    $error = syntaxErrorFor("L1\nL2\nL3\nL4\n{{ ( 1 + 2 }}");

    expect($error->templateLine)->toBe(5)
        ->and($error->templateSource)->toBe('( 1 + 2')
        ->and($error->getMessage())->toBe('Expected ")" but found end of expression on line 5 in "( 1 + 2"');
});

it('reports the line inside a multi-line antlers block', function (): void {
    expect(syntaxErrorFor("L1\n{{\n  name\n  )\n}}")->templateLine)->toBe(4);
});

it('keeps counting lines across blocks the scanner skips over', function (string $skipped): void {
    expect(syntaxErrorFor("L1\n" . $skipped . "\n{{ ( 1 }}")->templateLine)->toBe(5);
})->with([
    'escaped antlers' => ["@{{ a\nb\nc }}"],
    'comment'         => ["{{# a\nb\nc #}}"],
    'noparse'         => ["{{ noparse }}a\nb\nc{{ /noparse }}"],
]);

it('names the expected token in surface syntax, not as a token type', function (
    string $template,
    string $message,
): void {
    expect(syntaxErrorFor($template)->getMessage())->toStartWith($message);
})->with([
    'missing paren'   => ['{{ ( 1 + 2 }}', 'Expected ")" but found end of expression'],
    'wrong bracket'   => ['{{ $items[0) }}', 'Expected "]" but found ")"'],
    'stray paren'     => ['{{ name ) }}', 'Unexpected ")" in expression'],
    'modifier name'   => ['{{ name | 1 }}', 'Expected a modifier name but found "1"'],
    'bad character'   => ['{{ a @ b }}', 'Unexpected character "@"'],
    'open string'     => ['{{ "hello }}', 'Unterminated string'],
    'open ternary'    => ['{{ a ? b }}', 'Unterminated ternary expression'],
    'variable path'   => ['{{ $user: }}', 'Expected an identifier after ":" in a variable path'],
]);

it('reports the line of a pairing error', function (): void {
    expect(syntaxErrorFor("L1\n{{ if true }}A\n\n{{ /foreach }}")->templateLine)->toBe(4)
        ->and(syntaxErrorFor("L1\n\n{{ if true }}A")->templateLine)->toBe(3);
});
