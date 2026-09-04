<?php

declare(strict_types=1);

use Bugo\Antlers\Support\CommonMarkRenderer;
use Bugo\Antlers\Support\MarkdownRendererInterface;
use League\CommonMark\CommonMarkConverter;

/*
 * Antlers does not auto-escape {{ }} output, so `markdown` is the designated
 * transform for untrusted prose. These tests pin that contract.
 */

it('escapes raw HTML in markdown source', function (): void {
    expect(engine()->render('{{ bio | markdown }}', ['bio' => '<script>alert(1)</script>']))
        ->toBe('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and(engine()->render('{{ bio | markdown }}', ['bio' => '<img src=x onerror=alert(1)>']))
        ->toBe('&lt;img src=x onerror=alert(1)&gt;');
});

it('does not let a link target inject attributes', function (): void {
    $output = engine()->render('{{ bio | markdown }}', ['bio' => '[click](" onmouseover="alert(1))']);

    expect($output)->not->toContain('onmouseover="alert')
        ->and($output)->toBe('<p>[click](&quot; onmouseover=&quot;alert(1))</p>');
});

it('drops unsafe link schemes', function (): void {
    expect(engine()->render('{{ bio | markdown }}', ['bio' => '[click](javascript:alert(1))']))
        ->toBe('<p><a>click</a></p>')
        ->and(engine()->render('{{ bio | markdown }}', ['bio' => '[click](/safe)']))
        ->toBe('<p><a href="/safe">click</a></p>');
});

it('renders commonmark constructs', function (): void {
    expect(engine()->render('{{ v | markdown }}', ['v' => '#### Deep']))->toBe('<h4>Deep</h4>')
        ->and(engine()->render('{{ v | markdown }}', ['v' => '_em_']))->toBe('<p><em>em</em></p>')
        ->and(engine()->render('{{ v | markdown }}', ['v' => '***both***']))
        ->toBe('<p><em><strong>both</strong></em></p>')
        ->and(engine()->render('{{ v | markdown }}', ['v' => "- a\n- b"]))
        ->toBe("<ul>\n<li>a</li>\n<li>b</li>\n</ul>")
        ->and(engine()->render('{{ v | markdown }}', ['v' => "```\n<b>x</b>\n```"]))
        ->toBe("<pre><code>&lt;b&gt;x&lt;/b&gt;\n</code></pre>")
        ->and(engine()->render('{{ v | markdown }}', ['v' => 'a \*b\* c']))->toBe('<p>a *b* c</p>');
});

it('uses a replaced renderer for both the tag and the modifier', function (): void {
    $engine = engine()->setMarkdownRenderer(new class implements MarkdownRendererInterface {
        public function render(string $markdown): string
        {
            return '[' . $markdown . ']';
        }
    });

    expect($engine->render('{{ v | markdown }}', ['v' => 'raw']))->toBe('[raw]')
        ->and($engine->render('{{ markdown }}raw{{ /markdown }}'))->toBe('[raw]');
});

it('accepts a pre-configured commonmark converter', function (): void {
    $renderer = new CommonMarkRenderer(new CommonMarkConverter(['html_input' => 'allow']));

    expect($renderer->render('<b>raw</b>'))->toBe('<p><b>raw</b></p>')
        ->and((new CommonMarkRenderer())->render('<b>raw</b>'))->toBe('<p>&lt;b&gt;raw&lt;/b&gt;</p>');
});
