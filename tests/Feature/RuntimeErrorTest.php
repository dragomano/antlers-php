<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;

/*
 * Runtime failures point at a template line too, so "Undefined variable" is
 * actionable in a template of any size. The line is attached as the exception
 * leaves the statement being rendered, which is the innermost {{ }} that can
 * still be blamed for it.
 */

/** @param array<string, mixed> $data */
function runtimeErrorFor(string $template, array $data = []): AntlersRuntimeException
{
    try {
        engine()->setStrictMode(true)->render($template, $data);
    } catch (AntlersRuntimeException $e) {
        return $e;
    }

    throw new RuntimeException('Expected AntlersRuntimeException was not thrown.');
}

it('reports the template line of a runtime failure', function (): void {
    $error = runtimeErrorFor("L1\nL2\nL3\nL4\n{{ missing }}");

    expect($error->templateLine)->toBe(5)
        ->and($error->getMessage())->toBe('Undefined variable: "missing" on line 5');
});

it('blames the statement that failed, not the block around it', function (
    string $template,
    int $line,
): void {
    expect(runtimeErrorFor($template, ['n' => [['a' => 1]]])->templateLine)->toBe($line);
})->with([
    'inside a condition'   => ["L1\n{{ if true }}\n{{ missing }}\n{{ /if }}", 3],
    'inside a loop'        => ["L1\n{{ n }}\n{{ missing }}\n{{ /n }}", 3],
    'inside both'          => ["L1\n{{ n }}\n{{ if true }}\n{{ missing }}\n{{ /if }}\n{{ /n }}", 4],
    'inside a tag body'    => ["L1\n{{ scope:s }}\n{{ missing }}\n{{ /scope:s }}", 3],
    'inside an assignment' => ["L1\n{{ r = 1 }}\n{{ missing }}\n{{ /r }}", 3],
    'multi-line statement' => ["L1\n{{\n  missing\n}}", 3],
]);

it('reports the line of a failure raised by a tag', function (
    string $template,
    string $message,
): void {
    expect(runtimeErrorFor($template)->getMessage())->toBe($message);
})->with([
    'loop without bounds' => ["L1\nL2\n{{ loop }}x{{ /loop }}", 'Loop tag requires "times" or "to". on line 3'],
    'svg without src'     => ["L1\n{{ svg }}", 'Svg tag requires a "src" parameter. on line 2'],
]);

it('keeps the original failure in the exception chain', function (): void {
    $error = runtimeErrorFor("L1\nL2\n{{ 1 / 0 }}");

    expect($error->getMessage())->toBe('Division by zero on line 3')
        ->and($error->getPrevious())->toBeInstanceOf(AntlersRuntimeException::class)
        ->and($error->getPrevious()?->getMessage())->toBe('Division by zero');
});
