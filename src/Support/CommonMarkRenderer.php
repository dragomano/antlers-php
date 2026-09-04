<?php

declare(strict_types=1);

namespace Bugo\Antlers\Support;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\ConverterInterface;

/**
 * Default Markdown renderer, backed by league/commonmark — the same parser
 * Statamic uses, so output matches for templates moved over from Statamic.
 *
 * Raw HTML is escaped and unsafe link schemes are dropped, because `markdown`
 * is the designated transform for untrusted prose. Pass your own converter to
 * change that:
 *
 *   new CommonMarkRenderer(new CommonMarkConverter(['html_input' => 'allow']))
 */
final readonly class CommonMarkRenderer implements MarkdownRendererInterface
{
    private ConverterInterface $converter;

    public function __construct(?ConverterInterface $converter = null)
    {
        $this->converter = $converter ?? new CommonMarkConverter([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    public function render(string $markdown): string
    {
        return trim($this->converter->convert($markdown)->getContent());
    }
}
