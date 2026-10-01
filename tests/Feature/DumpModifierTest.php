<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;

it('dumps the piped value in the same view as the dump tag', function (): void {
    $engine = engine()->setDebug(true);
    $data   = ['user' => ['name' => 'Alice']];

    expect($engine->render('{{ user | dump }}', $data))
        ->toBe($engine->render('{{ dump value=user }}', $data))
        ->toContain('&apos;name&apos; =&gt; &apos;Alice&apos;');
});

it('stays silent while debug mode is off, in lenient and strict', function (): void {
    expect(engine()->render('{{ items | dump }}', ['items' => [1, 2]]))->toBe('')
        ->and(engine()->setStrictMode(true)->render('{{ items | dump }}', ['items' => [1, 2]]))->toBe('');
});

it('escapes dumped values so they cannot break out of the pre block', function (): void {
    $output = engine()->setDebug(true)->render('{{ payload | dump }}', [
        'payload' => '</pre><script>alert(1)</script>',
    ]);

    expect($output)->not->toContain('<script>')
        ->and($output)->toContain('&lt;script&gt;');
});

it('dumps values arriving through a modifier chain', function (): void {
    expect(engine()->setDebug(true)->render('{{ name | upper | dump }}', ['name' => 'Alice']))
        ->toContain('&apos;ALICE&apos;');
});

it('is guardable like any other modifier', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(modifiers: ['dump']));

    expect($engine->render('{{ name | dump }}', ['name' => 'Alice']))->toBe('Alice')
        ->and(fn(): string => $engine->setStrictMode(true)->render('{{ name | dump }}', ['name' => 'Alice']))
        ->toThrow(AntlersRuntimeException::class, 'Guarded modifier: "dump"');
});
