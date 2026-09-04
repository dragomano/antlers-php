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

    /** @var list<AbstractNode> */
    private array $nodes = [];

    // Tags that are always block-level (require a closing {{ /tag }})
    private const BUILTIN_BLOCKS = [
        'if', 'unless', 'foreach', 'for',
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
                $noparseOpenEnd = $this->findNoparseOpenEnd();

                $this->flushLiteral($literalStart, $this->pos);

                if ($noparseOpenEnd !== null) {

                    $this->nodes[] = $this->readNoparseBlock($noparseOpenEnd);

                    $literalStart = $this->pos;

                    continue;
                }

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

    private function readNoparseBlock(int $openEnd): AntlersNode
    {
        $startLine = $this->line;

        $this->pos += 2;

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

    private function findNoparseOpenEnd(): ?int
    {
        $end = strpos($this->template, '}}', $this->pos + 2);
        if ($end === false) {
            return null;
        }

        return trim(substr($this->template, $this->pos + 2, $end - ($this->pos + 2))) === 'noparse'
            ? $end
            : null;
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
     * @param  list<AbstractNode> $nodes
     * @return AbstractNode[]
     */
    private function matchPairs(array $nodes): array
    {
        $pairedOpenings = $this->resolvePairedOpenings($nodes);

        $result = [];
        /** @var AntlersNode[] $stack */
        $stack  = [];

        foreach ($nodes as $index => $node) {
            if (! ($node instanceof AntlersNode)) {
                $this->appendNode($node, $result, $stack);

                continue;
            }

            if ($node->isClosingTag) {
                $this->closeBlock($node, $stack);

                continue;
            }

            $isBlock = $this->isBlockTag($node, isset($pairedOpenings[$index]));

            $this->appendNode($node, $result, $stack);

            if ($isBlock) {
                // Push onto stack so subsequent nodes become children
                $stack[] = $node;
            }
        }

        if ($stack !== []) {
            $unclosed = $stack[count($stack) - 1];

            throw new AntlersSyntaxException(
                sprintf('Unclosed tag {{ %s }}', $unclosed->name),
                $unclosed->line,
            );
        }

        return $result;
    }

    /**
     * Closes the innermost open block, which must be the one this tag names.
     *
     * @param AntlersNode[] $stack
     */
    private function closeBlock(AntlersNode $node, array &$stack): void
    {
        if ($stack === []) {
            throw new AntlersSyntaxException(
                sprintf('Unexpected closing tag {{ /%s }}', $node->name),
                $node->line,
            );
        }

        $open = array_pop($stack);

        if ($open->name !== $node->name) {
            throw new AntlersSyntaxException(
                sprintf(
                    'Unexpected closing tag {{ /%s }}, expected {{ /%s }}',
                    $node->name,
                    $open->name,
                ),
                $node->line,
            );
        }

        // Children were already accumulated into $open->children
        $open->closingPair = $node;
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
     * Antlers tags are ambiguous: {{ items }} is a variable unless a matching
     * {{ /items }} closes it. Decide that per name with the same balanced
     * matching the main pass uses, so an occurrence closed somewhere else in
     * the template cannot turn every occurrence of that name into a block.
     *
     * @param  list<AbstractNode> $nodes
     * @return array<int, true>   indices of opening nodes that have a matching close
     */
    private function resolvePairedOpenings(array $nodes): array
    {
        /** @var array<string, list<int>> $openIndexes */
        $openIndexes = [];
        $paired      = [];

        foreach ($nodes as $index => $node) {
            if (! ($node instanceof AntlersNode)) {
                continue;
            }

            if (! $node->isClosingTag) {
                $openIndexes[$node->name][] = $index;

                continue;
            }

            $pending = $openIndexes[$node->name] ?? [];
            if ($pending === []) {
                continue;
            }

            $paired[array_pop($pending)] = true;

            $openIndexes[$node->name] = $pending;
        }

        return $paired;
    }

    private function isBlockTag(AntlersNode $node, bool $hasMatchingClose): bool
    {
        // Always block
        if (in_array($node->name, self::BUILTIN_BLOCKS, strict: true)) {
            return true;
        }

        // Never block
        if (in_array($node->name, self::ALWAYS_SELF_CLOSING, strict: true)) {
            return false;
        }

        return $hasMatchingClose;
    }
}
