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

it('supports partial slots and named slots', function (): void {
    $wrapper = fixturePath('partial/slots/wrapper.antlers.html');

    expect(rtrim(engine()->renderFile($wrapper, ['title' => 'Hello', 'body' => 'Body copy'])))
        ->toBe('<div class="modal"><div class="modal-header"><h1>Hello</h1></div><div class="modal-content"><p>Body copy</p></div></div>');
});

it('supports layout rendering with template_content', function (): void {
    $child = fixturePath('layout/basic/child.antlers.html');

    expect(rtrim(engine()->renderFile($child, ['title' => 'Welcome'])))->toBe('<body><main>Welcome</main></body>');
});

it('supports sections inside layouts and passes layout parameters', function (): void {
    $child = fixturePath('layout/sections/child.antlers.html');

    expect(rtrim(engine()->renderFile($child, ['title' => 'Welcome', 'body' => 'Body copy'])))
        ->toBe('<h1>Welcome</h1>|<aside>blue</aside>|<p>Body copy</p>');
});

it('supports layout slots and named slot fallback content', function (): void {
    $child = fixturePath('layout/slots/child.antlers.html');

    expect(rtrim(engine()->renderFile($child, ['title' => 'Welcome', 'body' => 'Body copy'])))
        ->toBe('<body><aside><nav>Welcome</nav></aside><main><p>Body copy</p></main><footer>Fallback Footer</footer></body>');
});

it('keeps partial local parameters scoped to the partial render only', function (): void {
    $wrapper = fixturePath('partial/scope/wrapper.antlers.html');

    expect(engine()->renderFile($wrapper, ['title' => 'Outer']))->toBe('Inner|Outer');
});

it('lets partial local parameters shadow globals without leaking back to the caller scope', function (): void {
    $e = engine();
    $e->addGlobal('title', 'Global');

    expect($e->renderFile(fixturePath('partial/scope/wrapper.antlers.html')))->toBe('Inner|Global');
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

it('supports push prepend and stack tags', function (): void {
    $tpl = <<<'ANTLERS'
    {{ push:scripts }}<script src="/app.js"></script>{{ /push:scripts }}
    {{ prepend:scripts }}<script src="/polyfill.js"></script>{{ /prepend:scripts }}
    {{ push name="scripts" }}<script src="/analytics.js"></script>{{ /push }}
    {{ stack:scripts }}<p>fallback</p>{{ /stack:scripts }}
    ANTLERS;

    expect(engine()->render($tpl))->toBe(
        "\n\n\n<script src=\"/polyfill.js\"></script><script src=\"/app.js\"></script><script src=\"/analytics.js\"></script>",
    );
});

it('supports stack fallback content when the stack is empty', function (): void {
    $tpl = '{{ stack:styles }}<style>.page{display:block;}</style>{{ /stack:styles }}';

    expect(engine()->render($tpl))->toBe('<style>.page{display:block;}</style>');
});

it('supports once blocks only once per render pass', function (): void {
    $tpl = <<<'ANTLERS'
    {{ loop times="3" }}{{ once }}<script src="/app.js"></script>{{ /once }}{{ /loop }}
    ANTLERS;

    expect(engine()->render($tpl))->toBe('<script src="/app.js"></script>');
});

it('supports keyed once blocks across different locations', function (): void {
    $tpl = <<<'ANTLERS'
    {{ once:scripts }}<script src="/app.js"></script>{{ /once:scripts }}
    {{ once name="scripts" }}<script src="/app.js"></script>{{ /once }}
    {{ once:styles }}<style>.page{display:block;}</style>{{ /once:styles }}
    ANTLERS;

    expect(engine()->render($tpl))->toBe("<script src=\"/app.js\"></script>\n\n<style>.page{display:block;}</style>");
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

it('blocks partial path traversal outside the current template root', function (): void {
    expect(rtrim(engine()->renderFile(fixturePath('partial/security/wrapper.antlers.html'))))->toBe('');
});

it('blocks svg path traversal outside the current template root', function (): void {
    expect(rtrim(engine()->renderFile(fixturePath('svg/security/template.antlers.html'))))->toBe('');
});

it('supports increment tag', function (): void {
    $tpl = '{{ increment }},{{ increment }},{{ increment:row from="10" by="5" }},{{ increment:row from="10" by="5" }}';

    expect(engine()->render($tpl))->toBe('1,2,10,15');
});
