<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;
use Bugo\Antlers\Exceptions\AntlersRuntimeException;

function dateEngine(): Engine
{
    return engine()->setDateModifiers();
}

it('keeps the date modifiers opt-in', function (): void {
    engine()->setStrictMode(true)
        ->render('{{ d | timestamp }}', ['d' => 1711929600]);
})->throws(AntlersRuntimeException::class, 'Unknown modifier: "timestamp"');

it('registers the date modifiers together with the core after opting in', function (): void {
    expect(dateEngine()->render('{{ d | timestamp }}', ['d' => 1711929600]))->toBe('1711929600')
        ->and(dateEngine()->render('{{ name | upper }}', ['name' => 'ok']))->toBe('OK');
});

it('applies timestamp modifier', function (): void {
    expect(dateEngine()->render('{{ d | timestamp }}', ['d' => '2024-04-01 00:00:00 UTC']))->toBe('1711929600')
        ->and(dateEngine()->render('{{ d | timestamp }}', ['d' => '1711929600']))->toBe('1711929600')
        ->and(dateEngine()->render('{{ d | timestamp }}', ['d' => new DateTimeImmutable('2024-04-01 00:00:00 UTC')]))
        ->toBe('1711929600');
});

it('returns the original value when the date cannot be parsed', function (): void {
    expect(dateEngine()->render('{{ d | timestamp }}', ['d' => 'not a date']))->toBe('not a date')
        ->and(dateEngine()->render('{{ d | ago }}', ['d' => 'not a date']))->toBe('not a date');
});

it('formats past dates as time ago', function (): void {
    expect(dateEngine()->render('{{ d | ago }}', [
        'd' => (new DateTimeImmutable())->modify('-3 days')->modify('-12 hours')->format('c'),
    ]))->toBe('3 days ago')
        ->and(dateEngine()->render('{{ d | ago }}', [
            'd' => (new DateTimeImmutable())->modify('-5 hours')->modify('-3 minutes')->format('c'),
        ]))->toBe('5 hours ago')
        ->and(dateEngine()->render('{{ d | ago }}', [
            'd' => (new DateTimeImmutable())->modify('-59 minutes')->format('c'),
        ]))->toBe('59 minutes ago')
        ->and(dateEngine()->render('{{ d | ago }}', [
            'd' => (new DateTimeImmutable())->modify('-1 day')->modify('-12 hours')->format('c'),
        ]))->toBe('1 day ago');
});

it('formats future dates as relative time', function (): void {
    expect(dateEngine()->render('{{ d | ago }}', [
        'd' => (new DateTimeImmutable())->modify('+2 months')->modify('+5 days')->format('c'),
    ]))->toBe('in 2 months')
        ->and(dateEngine()->render('{{ d | ago }}', [
            'd' => (new DateTimeImmutable())->modify('+1 second'),
        ]))->toBe('in 1 second');
});

it('treats the current moment as one second ago', function (): void {
    expect(dateEngine()->render('{{ d | ago }}', ['d' => date('Y-m-d H:i:s')]))->toBe('1 second ago');
});
