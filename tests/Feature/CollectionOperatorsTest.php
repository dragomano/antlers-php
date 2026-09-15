<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;

function engineWithCollectionJson(): Engine
{
    $engine = engine();
    $engine->addModifier(
        'to_json',
        fn($v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );

    return $engine;
}

it('supports merge as a standalone collection operator', function (): void {
    expect(engineWithCollectionJson()->render('{{ items merge extras | to_json }}', [
        'items'  => [1, 2],
        'extras' => [3, 4],
    ]))->toBe('[1,2,3,4]');
});

it('supports where and take as standalone collection operators', function (): void {
    expect(engineWithCollectionJson()->render('{{ items where (active == true) take (2) | to_json }}', [
        'items' => [
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
            ['name' => 'Cara', 'active' => true],
            ['name' => 'Dina', 'active' => true],
        ],
    ]))->toBe('[{"name":"Alice","active":true},{"name":"Cara","active":true}]');
});

it('supports where arrow functions from official antlers syntax', function (): void {
    expect(engineWithCollectionJson()->render('{{ items where (x => x.price < budget) pluck (name) | to_json }}', [
        'budget' => 50,
        'items'  => [
            ['name' => 'Talkboy', 'price' => 30],
            ['name' => 'Super Nintendo', 'price' => 90],
            ['name' => 'Pogs', 'price' => 1],
        ],
    ]))->toBe('["Talkboy","Pogs"]');
});

it('supports skip and pluck as standalone collection operators', function (): void {
    expect(engineWithCollectionJson()->render("{{ items skip (1) pluck ('name') | to_json }}", [
        'items' => [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Cara'],
        ],
    ]))->toBe('["Bob","Cara"]');
});

it('supports orderby with multiple official sort clauses', function (): void {
    expect(engineWithCollectionJson()->render('{{ items orderby (score false, name true) pluck (name) | to_json }}', [
        'items' => [
            ['name' => 'Bob', 'score' => 7],
            ['name' => 'Alice', 'score' => 10],
            ['name' => 'Cara', 'score' => 7],
        ],
    ]))->toBe('["Alice","Bob","Cara"]');
});

it('supports groupby as a standalone collection operator', function (): void {
    expect(engineWithCollectionJson()->render('{{ items groupby (role) | to_json }}', [
        'items' => [
            ['name' => 'Alice', 'role' => 'admin'],
            ['name' => 'Bob', 'role' => 'editor'],
            ['name' => 'Cara', 'role' => 'admin'],
        ],
    ]))->toBe('[{"role":"admin","key":"admin","group":"admin","items":[{"name":"Alice","role":"admin"},{"name":"Cara","role":"admin"}]},{"role":"editor","key":"editor","group":"editor","items":[{"name":"Bob","role":"editor"}]}]');
});

it('streams grouped frames through the group and items fields', function (): void {
    expect(engine()->render('{{ res = items groupby (role) }}{{ res }}{{ group }}:{{ items }}{{ name }},{{ /items }};{{ /res }}', [
        'items' => [
            ['name' => 'Alice', 'role' => 'admin'],
            ['name' => 'Bob', 'role' => 'editor'],
            ['name' => 'Cara', 'role' => 'admin'],
        ],
    ]))->toBe('admin:Alice,Cara,;editor:Bob,;');
});

it('supports official groupby aliases and a custom items alias', function (): void {
    expect(engineWithCollectionJson()->render(
        "{{ players groupby (team 'club', position) as 'entries' | to_json }}",
        [
            'players' => [
                ['team' => 'Bulls', 'position' => 'Guard', 'name' => 'Jordan'],
                ['team' => 'Bulls', 'position' => 'Forward', 'name' => 'Pippen'],
                ['team' => 'Bulls', 'position' => 'Forward', 'name' => 'Rodman'],
                ['team' => 'Pistons', 'position' => 'Guard', 'name' => 'Thomas'],
            ],
        ],
    ))->toBe('[{"club":"Bulls","position":"Guard","key":{"club":"Bulls","position":"Guard"},"group":{"club":"Bulls","position":"Guard"},"entries":[{"team":"Bulls","position":"Guard","name":"Jordan"}],"items":[{"team":"Bulls","position":"Guard","name":"Jordan"}]},{"club":"Bulls","position":"Forward","key":{"club":"Bulls","position":"Forward"},"group":{"club":"Bulls","position":"Forward"},"entries":[{"team":"Bulls","position":"Forward","name":"Pippen"},{"team":"Bulls","position":"Forward","name":"Rodman"}],"items":[{"team":"Bulls","position":"Forward","name":"Pippen"},{"team":"Bulls","position":"Forward","name":"Rodman"}]},{"club":"Pistons","position":"Guard","key":{"club":"Pistons","position":"Guard"},"group":{"club":"Pistons","position":"Guard"},"entries":[{"team":"Pistons","position":"Guard","name":"Thomas"}],"items":[{"team":"Pistons","position":"Guard","name":"Thomas"}]}]')
        ->and(engineWithCollectionJson()->render("{{ players groupby (team 'club') | to_json }}", [
            'players' => [
                ['team' => 'Bulls', 'position' => 'Guard', 'name' => 'Jordan'],
                ['team' => 'Pistons', 'position' => 'Guard', 'name' => 'Thomas'],
            ],
        ]))->toBe('[{"club":"Bulls","key":"Bulls","group":"Bulls","items":[{"team":"Bulls","position":"Guard","name":"Jordan"}]},{"club":"Pistons","key":"Pistons","group":"Pistons","items":[{"team":"Pistons","position":"Guard","name":"Thomas"}]}]');
});

it('evaluates arithmetic before standalone collection operators', function (): void {
    expect(engineWithCollectionJson()->render('{{ items take (1 + 1) | to_json }}', [
        'items' => [1, 2, 3, 4],
    ]))->toBe('[1,2]');
});
