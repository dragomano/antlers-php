<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

use Bugo\Antlers\Exceptions\AntlersSyntaxException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Nodes\LiteralNode;

/**
 * Stage 1: Scans a template string and produces a flat list of nodes,
 * then pairs up opening/closing block tags into a tree structure.
 */
final class DocumentParser
{
    private string $template = '';
    private int $length = 0;
    private int $pos = 0;
    private int $line = 1;

    /** @var AbstractNode[] */
    private array $nodes = [];

    // Tags that are always block-level (require a closing {{ /tag }})
    private const BUILTIN_BLOCKS = [
        'if', 'unless', 'foreach', 'for',
        'cache', 'markdown',
    ];

    // Tags that are always self-closing (never paired)
    private const ALWAYS_SELF_CLOSING = [
        'else', 'elseif', 'true', 'false', 'null', 'set',
    ];

    /**
     * @return AbstractNode[]
     */
    public function parse(string $template): array
    {
        $this->template = $template;
        $this->length   = strlen($template);
        $this->pos      = 0;
        $this->line     = 1;
        $this->nodes    = [];

        $this->scan();

        return $this->matchPairs($this->nodes);
    }

    private function scan(): void
    {
        $literalStart = 0;

        while ($this->pos < $this->length) {
            // Escaped antlers: @{{ ... }}  →  emit as literal {{ ... }}
            if ($this->matchAt('@{{')) {
                $this->flushLiteral($literalStart, $this->pos);

                $this->pos += 3;

                $end = strpos($this->template, '}}', $this->pos);

                if ($end === false) {
                    throw new AntlersSyntaxException('Unclosed escaped antlers @{{', $this->line);
                }

                $inner = substr($this->template, $this->pos, $end - $this->pos);

                $this->nodes[] = $this->makeLiteral('{{' . $inner . '}}');

                $this->pos = $end + 2;

                $literalStart = $this->pos;

                continue;
            }

            // Comment: {{# ... #}}
            if ($this->matchAt('{{#')) {
                $this->flushLiteral($literalStart, $this->pos);

                $this->pos += 3;

                $end = strpos($this->template, '#}}', $this->pos);

                if ($end === false) {
                    throw new AntlersSyntaxException('Unclosed Antlers comment {{#', $this->line);
                }

                $this->line += substr_count(substr($this->template, $this->pos, $end - $this->pos), "\n");

                $this->pos = $end + 3;

                $literalStart = $this->pos;

                continue;
            }

            // Antlers block: {{ ... }}
            if ($this->matchAt('{{')) {
                if ($this->isNoparseStart()) {
                    $this->flushLiteral($literalStart, $this->pos);

                    $this->nodes[] = $this->readNoparseBlock();

                    $literalStart = $this->pos;

                    continue;
                }

                $this->flushLiteral($literalStart, $this->pos);

                $this->pos += 2;

                $node = $this->readAntlersBlock();
                if ($node instanceof AntlersNode) {
                    $this->nodes[] = $node;
                }

                $literalStart = $this->pos;

                continue;
            }

            if ($this->template[$this->pos] === "\n") {
                $this->line++;
            }

            $this->pos++;
        }

        $this->flushLiteral($literalStart, $this->pos);
    }

    private function readAntlersBlock(): ?AntlersNode
    {
        $startLine = $this->line;

        $end = strpos($this->template, '}}', $this->pos);
        if ($end === false) {
            throw new AntlersSyntaxException('Unclosed Antlers tag {{', $this->line);
        }

        $raw = substr($this->template, $this->pos, $end - $this->pos);

        $this->line += substr_count($raw, "\n");

        $this->pos = $end + 2;

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $node               = new AntlersNode();
        $node->rawContent   = $trimmed;
        $node->line         = $startLine;
        $node->isClosingTag = str_starts_with($trimmed, '/');
        $node->name         = $this->extractTagName($trimmed);

        return $node;
    }

    private function readNoparseBlock(): AntlersNode
    {
        $startLine = $this->line;

        $this->pos += 2;

        $openEnd = strpos($this->template, '}}', $this->pos);
        if ($openEnd === false) {
            throw new AntlersSyntaxException('Unclosed Antlers tag {{', $this->line);
        }

        $openingRaw = substr($this->template, $this->pos, $openEnd - $this->pos);

        $this->line += substr_count($openingRaw, "\n");

        $this->pos = $openEnd + 2;

        $contentStart = $this->pos;
        $depth        = 1;

        while ($this->pos < $this->length) {
            $nextOpen = strpos($this->template, '{{', $this->pos);
            if ($nextOpen === false) {
                throw new AntlersSyntaxException('Unclosed noparse block {{ noparse }}', $startLine);
            }

            $this->line += substr_count(substr($this->template, $this->pos, $nextOpen - $this->pos), "\n");

            $this->pos = $nextOpen + 2;

            $end = strpos($this->template, '}}', $this->pos);
            if ($end === false) {
                throw new AntlersSyntaxException('Unclosed Antlers tag {{', $this->line);
            }

            $raw     = substr($this->template, $this->pos, $end - $this->pos);
            $trimmed = trim($raw);

            $this->line += substr_count($raw, "\n");

            $this->pos = $end + 2;

            if ($trimmed === 'noparse') {
                $depth++;

                continue;
            }

            if ($trimmed !== '/noparse') {
                continue;
            }

            $depth--;

            if ($depth !== 0) {
                continue;
            }

            $node             = new AntlersNode();
            $node->rawContent = 'noparse';
            $node->line       = $startLine;
            $node->name       = 'noparse';
            $node->children[] = new LiteralNode(substr($this->template, $contentStart, $nextOpen - $contentStart));

            return $node;
        }

        throw new AntlersSyntaxException('Unclosed noparse block {{ noparse }}', $startLine);
    }

    private function isNoparseStart(): bool
    {
        $end = strpos($this->template, '}}', $this->pos + 2);
        if ($end === false) {
            return false;
        }

        return trim(substr($this->template, $this->pos + 2, $end - ($this->pos + 2))) === 'noparse';
    }

    private function extractTagName(string $content): string
    {
        $content = ltrim($content, '/ ');
        // Match first identifier (may include colon for tag:method)
        if (preg_match('/^([%$]?\w+(?::\w+)?)/', $content, $m)) {
            return strtolower($m[1]);
        }

        return strtolower((string) preg_replace('/\s.*/', '', $content));
    }

    private function flushLiteral(int $from, int $to): void
    {
        if ($to > $from) {
            $content = substr($this->template, $from, $to - $from);
            if ($content !== '') {
                $this->nodes[] = $this->makeLiteral($content);
            }
        }
    }

    private function makeLiteral(string $content): LiteralNode
    {
        $node = new LiteralNode($content);
        $node->line = $this->line;

        return $node;
    }

    private function matchAt(string $needle): bool
    {
        return substr($this->template, $this->pos, strlen($needle)) === $needle;
    }

    /**
     * @param  AbstractNode[] $nodes
     * @return AbstractNode[]
     */
    private function matchPairs(array $nodes): array
    {
        // Pre-compute which simple-identifier tags have a matching closing tag
        $pairedNames = $this->findPairedNames($nodes);

        $result = [];
        /** @var AntlersNode[] $stack */
        $stack  = [];

        foreach ($nodes as $node) {
            if (! ($node instanceof AntlersNode)) {
                $this->appendNode($node, $result, $stack);

                continue;
            }

            if ($node->isClosingTag) {
                if (empty($stack)) {
                    // Orphaned closing tag — ignore
                    continue;
                }

                $open = array_pop($stack);
                $open->closingPair = $node;

                // Children were already accumulated into $open->children
                continue;
            }

            $isBlock = $this->isBlockTag($node, $pairedNames);

            $this->appendNode($node, $result, $stack);

            if ($isBlock) {
                // Push onto stack so subsequent nodes become children
                $stack[] = $node;
            }
        }

        return $result;
    }

    /**
     * Appends a node to the current context (top of stack or root).
     *
     * @param AbstractNode[] $result root-level output
     * @param AntlersNode[]  $stack
     */
    private function appendNode(AbstractNode $node, array &$result, array $stack): void
    {
        if ($stack === []) {
            $result[] = $node;
        } else {
            $stack[count($stack) - 1]->children[] = $node;
        }
    }

    /**
     * Scans the flat node list and returns names of tags that have a matching
     * closing counterpart. Used to decide if a simple {{ varname }} is paired.
     *
     * @param  AbstractNode[] $nodes
     * @return array<string, bool>
     */
    private function findPairedNames(array $nodes): array
    {
        $openCounts   = [];
        $closingNames = [];

        foreach ($nodes as $node) {
            if (! ($node instanceof AntlersNode)) {
                continue;
            }

            $name = $node->name;
            if ($node->isClosingTag) {
                $closingNames[$name] = true;
            } else {
                $openCounts[$name] = ($openCounts[$name] ?? 0) + 1;
            }
        }

        $paired = [];
        foreach (array_keys($closingNames) as $name) {
            if (isset($openCounts[$name])) {
                $paired[$name] = true;
            }
        }

        return $paired;
    }

    /**
     * @param array<string, bool> $pairedNames
     */
    private function isBlockTag(AntlersNode $node, array $pairedNames): bool
    {
        $name = $node->name;

        // Always block
        if (in_array($name, self::BUILTIN_BLOCKS, strict: true)) {
            return true;
        }

        // Never block
        if (in_array($name, self::ALWAYS_SELF_CLOSING, strict: true)) {
            return false;
        }

        // Tags with colon are block tags only when a matching closing tag exists.
        if (str_contains($name, ':')) {
            return isset($pairedNames[$name]);
        }

        // If there is a matching {{ /name }} anywhere, treat as block tag.
        // This covers: simple vars {{ items }}, tags with params {{ wrap tag="p" }}.
        // Expressions with spaces or operators and no matching close tag — self-closing
        return isset($pairedNames[$name]) && preg_match('/^[%$]?[\w.]+$/', $name);
    }
}
