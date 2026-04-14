<?php

declare(strict_types=1);

function fixturePath(string $relative): string
{
    return dirname(__DIR__) . '/Fixtures/CoreTags/' . $relative;
}

it('supports statamic foreach shorthand with key and value', function (): void {
    $tpl = <<<'ANTLERS'
    {{ foreach:company_info }}
    {{ key }}={{ value }}|
    {{ /foreach:company_info }}
    ANTLERS;

    $data = [
        'company_info' => [
            'address' => '123 Hollywood Blvd',
            'city'    => 'Beverly Hills',
        ],
    ];

    expect(engine()->render($tpl, $data))->toBe("address=123 Hollywood Blvd|\ncity=Beverly Hills|");
});

it('supports statamic foreach alias syntax', function (): void {
    $tpl = <<<'ANTLERS'
    {{ foreach:song_reviews as="song|rating" }}{{ song }}={{ rating }}|{{ /foreach:song_reviews }}
    ANTLERS;

    $data = ['song_reviews' => [
        'Never Gonna Give You Up' => '5/5',
        'My Heart Will Go On'     => '3/5',
    ]];

    expect(engine()->render($tpl, $data))->toBe('Never Gonna Give You Up=5/5|My Heart Will Go On=3/5|');
});

it('supports foreach array parameter forms', function (): void {
    $tpl = <<<'ANTLERS'
    {{ foreach :array="reviews:songs" as="song|rating" }}{{ song }}={{ rating }}|{{ /foreach }}
    ANTLERS;

    $data = ['reviews' => ['songs' => [
        'Track A' => '4/5',
        'Track B' => '5/5',
    ]]];

    expect(engine()->render($tpl, $data))->toBe('Track A=4/5|Track B=5/5|');
});

it('supports partial rendering and existence checks', function (): void {
    $partial = fixturePath('partial/basic/card.antlers.html');
    $wrapper = fixturePath('partial/basic/wrapper.antlers.html');

    expect(engine()->renderFile($partial, ['title' => 'Ignored']))->toBe('<h1>Ignored</h1>')
        ->and(engine()->renderFile($wrapper))->toBe('<h1>Hello</h1>|true|');
});

it('supports extensionless partial lookup consistently across render and existence checks', function (): void {
    $wrapper = fixturePath('partial/lookup/wrapper.antlers.html');

    expect(engine()->renderFile($wrapper))->toBe('<h1>Hello</h1>|true|<h1>Hello</h1>');
});

it('supports partial shorthand methods and yield fallback content', function (): void {
    $wrapper = fixturePath('partial/shorthand/wrapper.antlers.html');

    expect(engine()->renderFile($wrapper))->toBe('<header>Hello</header>|Fallback');
});

it('keeps partial local parameters scoped to the partial render only', function (): void {
    $wrapper = fixturePath('partial/scope/wrapper.antlers.html');

    expect(engine()->renderFile($wrapper, ['title' => 'Outer']))->toBe('Inner|Outer');
});

it('does not leak assignments made inside partials back to the caller scope', function (): void {
    $wrapper = fixturePath('partial/assignment/wrapper.antlers.html');

    expect(engine()->renderFile($wrapper, ['title' => 'Outer']))->toBe('Inner|Outer');
});

it('supports section and yield tags', function (): void {
    $tpl = <<<'ANTLERS'
    {{ section:hero }}<h1>{{ title }}</h1>{{ /section:hero }}
    {{ yield:hero }}
    ANTLERS;

    expect(engine()->render($tpl, ['title' => 'Welcome']))->toBe("\n<h1>Welcome</h1>");
});

it('supports markdown tags', function (): void {
    $tpl = '{{ markdown }}**Bold**{{ /markdown }}|{{ markdown:indent }}
        # Title
    {{ /markdown:indent }}';

    expect(engine()->render($tpl))->toBe('<p><strong>Bold</strong></p>|<h1>Title</h1>');
});

it('supports loop tag', function (): void {
    $tpl = '{{ loop times="3" }}{{ value }}{{ /loop }}';

    expect(engine()->render($tpl))->toBe('123');
});

it('supports loop shorthand count and start aliases', function (): void {
    $tpl = '{{ loop count="3" start="5" }}{{ value }}{{ /loop }}|{{ loop:2 }}{{ value }}{{ /loop:2 }}';

    expect(engine()->render($tpl))->toBe('567|12');
});

it('supports switch tag', function (): void {
    $tpl = '{{ switch between="odd|even" }}{{ switch between="odd|even" }}{{ switch between="odd|even" }}';

    expect(engine()->render($tpl))->toBe('oddevenodd');
});

it('supports switch in alias and named sequences', function (): void {
    $tpl = '{{ switch name="rows" in="a|b" }}{{ switch name="rows" in="a|b" }}{{ switch name="other" in="x|y" }}{{ switch name="rows" in="a|b" }}';

    expect(engine()->render($tpl))->toBe('abxa');
});

it('supports scope tag', function (): void {
    $tpl = '{{ scope:page }}{{ page:title }}{{ /scope:page }}';

    expect(engine()->render($tpl, ['title' => 'Homepage']))->toBe('Homepage');
});

it('supports dump tag', function (): void {
    $output = engine()->render('{{ dump value=user }}', ['user' => ['name' => 'Alice']]);

    expect($output)->toContain('<pre>')
        ->and($output)->toContain("'name' => 'Alice'");
});

it('supports svg tag', function (): void {
    expect(engine()->renderFile(fixturePath('svg/src/template.antlers.html')))->toBe('<svg><rect width="10" height="10"/></svg>');
});

it('supports svg name alias', function (): void {
    expect(engine()->renderFile(fixturePath('svg/name/template.antlers.html')))->toBe('<svg><circle r="4"/></svg>');
});

it('supports increment tag', function (): void {
    $tpl = '{{ increment }},{{ increment }},{{ increment:row from="10" by="5" }},{{ increment:row from="10" by="5" }}';

    expect(engine()->render($tpl))->toBe('1,2,10,15');
});
