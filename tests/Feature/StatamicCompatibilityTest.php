<?php

declare(strict_types=1);

/*
 * Executable notes on Statamic compatibility. Each case pins a construct that
 * is documented for Statamic Antlers, so a template moved over from Statamic
 * behaves the same way here.
 *
 * Constructs antlers-php deliberately does not support are out of scope — the
 * point of this file is that nothing behaves *differently* while looking the
 * same.
 */

describe('coalescing operators', function (): void {
    /*
     * Statamic: "The null coalescing operator ($a ?? $b) considers each variable
     * in a statement optional, returning the first one that passes a truthy
     * check." So ?? falls through on every falsy value, not just null.
     */
    it('falls back from any falsy left side', function (mixed $value): void {
        expect(engine()->render('{{ v ?? "fallback" }}', ['v' => $value]))->toBe('fallback');
    })->with([
        'null'         => [null],
        'false'        => [false],
        'empty string' => [''],
        'zero string'  => ['0'],
        'zero int'     => [0],
        'zero float'   => [0.0],
        'empty array'  => [[]],
    ]);

    it('falls back from an undefined left side', function (): void {
        expect(engine()->render('{{ missing ?? "fallback" }}'))->toBe('fallback');
    });

    it('keeps a truthy left side', function (): void {
        expect(engine()->render('{{ v ?? "fallback" }}', ['v' => 'Bob']))->toBe('Bob')
            ->and(engine()->render('{{ v ?? "fallback" }}', ['v' => 1]))->toBe('1')
            ->and(engine()->render('{{ v ?? "fallback" }}', ['v' => true]))->toBe('true');
    });

    /*
     * Statamic: "Use ??? to fall through only on null. Keeps 0, false, ''
     * intact."
     */
    it('keeps falsy but non-null values through the triple form', function (): void {
        expect(engine()->render('[{{ v ??? "fallback" }}]', ['v' => 0]))->toBe('[0]')
            ->and(engine()->render('[{{ v ??? "fallback" }}]', ['v' => false]))->toBe('[false]')
            ->and(engine()->render('[{{ v ??? "fallback" }}]', ['v' => '']))->toBe('[]')
            ->and(engine()->render('[{{ v ??? "fallback" }}]', ['v' => []]))->toBe('[]');
    });

    it('falls back from null through the triple form', function (): void {
        expect(engine()->render('{{ v ??? "fallback" }}', ['v' => null]))->toBe('fallback')
            ->and(engine()->render('{{ missing ??? "fallback" }}'))->toBe('fallback');
    });

    it('chains right to left', function (): void {
        expect(engine()->render('{{ a ?? b ?? "last" }}', ['a' => '', 'b' => '']))->toBe('last')
            ->and(engine()->render('{{ a ?? b ?? "last" }}', ['a' => '', 'b' => 'B']))->toBe('B')
            ->and(engine()->render('{{ a ??? b ??? "last" }}', ['a' => null, 'b' => 0]))->toBe('0');
    });

    it('mixes with modifier chains', function (): void {
        expect(engine()->render('{{ missing ?? "guest" | upper }}'))->toBe('GUEST')
            ->and(engine()->render('{{ missing ??? "guest" | upper }}'))->toBe('GUEST');
    });

    it('never throws for an undefined left side in strict mode', function (): void {
        $engine = engine()->setStrictMode(true);

        expect($engine->render('{{ missing ?? "fallback" }}'))->toBe('fallback')
            ->and($engine->render('{{ missing ??? "fallback" }}'))->toBe('fallback');
    });
});

describe('desirable modifiers', function (): void {
    /*
     * Statamic: "contains_all — The modifier searches a string for several
     * needles and only returns true if all of the needles are found."
     * The docs example checks a Tale of Two Cities excerpt.
     */
    it('checks several needles with contains_all', function (): void {
        $summary = 'It was the best of times, it was the worst of times.';

        expect(engine()->render('{{ summary | contains_all("best", "worst") }}', ['summary' => $summary]))
            ->toBe('true')
            ->and(engine()->render('{{ summary | contains_all("best", "better") }}', ['summary' => $summary]))
            ->toBe('false');
    });

    /*
     * Statamic: "compact — akin to PHP's own compact() function" — turns a
     * comma-separated list of variable names into an array of their values.
     * Colon paths reach into nested variables.
     */
    it('builds an array from variable names with compact', function (): void {
        expect(engine()->render('{{ list | compact | join:"|" }}', [
            'list'  => 'stuff:one, title, stuff:two',
            'title' => 'The finest title there ever was',
            'stuff' => ['one' => 'Value One', 'two' => 'Value Two'],
        ]))->toBe('Value One|The finest title there ever was|Value Two');
    });

    it('ensures and removes string prefixes and suffixes', function (): void {
        expect(engine()->render('{{ url | ensure_left:"www." }}', ['url' => 'example.com']))
            ->toBe('www.example.com')
            ->and(engine()->render('{{ url | ensure_right:"/" }}', ['url' => 'example.com']))
            ->toBe('example.com/')
            ->and(engine()->render('{{ url | remove_left:"www." }}', ['url' => 'www.example.com']))
            ->toBe('example.com')
            ->and(engine()->render('{{ file | remove_right:".php" }}', ['file' => 'index.php']))
            ->toBe('index');
    });

    /*
     * Statamic: "dashify — Returns a lowercase and trimmed string separated
     * by dashes" and "deslugify — Replaces hyphens and underscores with
     * spaces".
     */
    it('dasherizes and deslugifies strings', function (): void {
        expect(engine()->render('{{ text | dashify }}', ['text' => 'Foo Bar fooBar']))
            ->toBe('foo-bar-foo-bar')
            ->and(engine()->render('{{ text | deslugify }}', ['text' => 'my-first_post']))
            ->toBe('my first post');
    });

    /*
     * Statamic: "excerpt — Breaks a string at a given marker. Uses
     * <!--more--> by default."
     */
    it('breaks content at the excerpt marker', function (): void {
        expect(engine()->render('{{ content | excerpt }}', ['content' => 'First part<!--more-->Second part']))
            ->toBe('First part');
    });

    /*
     * Statamic: "to_qs — Converts the data to a query string" and
     * "parse_url — Get a URL component" with scheme/host/port/user/pass/
     * path/query/fragment keys.
     */
    it('serializes to query strings and pulls URL components', function (): void {
        expect(engine()->render('{{ values | to_qs }}', ['values' => ['page' => 2, 'q' => 'hello world']]))
            ->toBe('page=2&q=hello%20world')
            ->and(engine()->render('{{ url | parse_url:host }}', ['url' => 'https://example.com/path']))
            ->toBe('example.com')
            ->and(engine()->render('{{ path | pathinfo:extension }}', ['path' => '/docs/file.txt']))
            ->toBe('txt');
    });

    /*
     * Statamic: "timestamp — Converts a Carbon instance to a timestamp."
     * This engine runs the same modifier on strings and numbers through
     * PHP's own date APIs, as an explicit opt-in.
     */
    it('converts dates to timestamps after opting in', function (): void {
        expect(engine()->setDateModifiers()->render('{{ date | timestamp }}', ['date' => '2024-04-01 00:00:00 UTC']))
            ->toBe('1711929600');
    });
});
