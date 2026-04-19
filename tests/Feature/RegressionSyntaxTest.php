<?php

declare(strict_types=1);

it('keeps nested paired tags stable when conditions and loops are combined', function (): void {
    $tpl = <<<'ANTLERS'
    {{ items }}
    {{ if active }}{{ details }}{{ value }}{{ /details }}{{ else }}inactive{{ /if }}|
    {{ /items }}
    ANTLERS;

    expect(engine()->render($tpl, [
        'items' => [
            ['active' => true, 'details' => ['A', 'B']],
            ['active' => false, 'details' => ['C']],
        ],
    ]))->toBe("\nAB|\n\ninactive|\n");
});

it('keeps nested paired variables stable when the same name is also a registered tag', function (): void {
    $e = engine();
    $e->addTag('entries', fn(): string => 'tag-value');

    expect($e->render('{{ $entries }}{{ title }}:{{ tags }}{{ value }},{{ /tags }}|{{ /$entries }}', [
        'entries' => [
            ['title' => 'One', 'tags' => ['a', 'b']],
            ['title' => 'Two', 'tags' => ['c']],
        ],
    ]))->toBe('One:a,b,|Two:c,|');
});

it('keeps ambiguous ternary and modifier syntax stable inside nested blocks', function (): void {
    $tpl = <<<'ANTLERS'
    {{ users }}
    {{ if active }}{{ name ?? "guest" | upper }}{{ else }}{{ alt | lower }}{{ /if }}|
    {{ /users }}
    ANTLERS;

    expect(engine()->render($tpl, [
        'users' => [
            ['active' => true, 'name' => 'Alice', 'alt' => 'IGNORED'],
            ['active' => false, 'name' => null, 'alt' => 'GUEST'],
        ],
    ]))->toBe("\nALICE|\n\nguest|\n");
});
