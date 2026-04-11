<?php

declare(strict_types=1);

namespace Bugo\Antlers\Support;

final class MarkdownRenderer
{
    public static function render(string $markdown, bool $trimIndentation = false): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        if ($trimIndentation) {
            $markdown = self::trimSharedIndentation($markdown);
        }

        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $blocks = preg_split('/\n{2,}/', $markdown) ?: [];
        $html   = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/s', $block, $matches) === 1) {
                $level  = strlen($matches[1]);
                $html[] = sprintf('<h%d>%s</h%d>', $level, self::renderInline(trim($matches[2])), $level);

                continue;
            }

            $html[] = '<p>' . nl2br(self::renderInline($block)) . '</p>';
        }

        return implode('', $html);
    }

    private static function renderInline(string $text): string
    {
        $text = preg_replace('/\[(.+?)]\((.+?)\)/', '<a href="$2">$1</a>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', (string) $text);

        return (string) preg_replace('/\*(.+?)\*/s', '<em>$1</em>', (string) $text);
    }

    private static function trimSharedIndentation(string $markdown): string
    {
        $lines = explode("\n", $markdown);

        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        $indent = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            preg_match('/^[ \t]*/', $line, $matches);

            $lineIndent = strlen($matches[0] ?? '');
            $indent     = $indent === null ? $lineIndent : min($indent, $lineIndent);
        }

        if ($indent === null || $indent === 0) {
            return implode("\n", $lines);
        }

        return implode("\n", array_map(
            static fn(string $line): string => trim($line) === '' ? '' : substr($line, $indent),
            $lines,
        ));
    }
}
