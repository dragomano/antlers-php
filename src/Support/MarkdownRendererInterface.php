<?php

declare(strict_types=1);

namespace Bugo\Antlers\Support;

/**
 * Renders a Markdown string to HTML.
 *
 * Antlers does not auto-escape {{ }} output, so `markdown` is the designated
 * transform for untrusted prose. Implementations are expected to escape raw
 * HTML in the source and to reject unsafe link schemes.
 */
interface MarkdownRendererInterface
{
    public function render(string $markdown): string;
}
