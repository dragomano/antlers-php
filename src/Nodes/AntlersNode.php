<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

/**
 * Represents a {{ ... }} block in the template.
 * After parsing, the `expression` property holds the root AST node.
 * If this is a paired tag ({{ tag }}...{{ /tag }}), `children` holds the inner nodes.
 */
final class AntlersNode extends AbstractNode
{
    public string $rawContent = '';

    // Parsed AST expression root
    public ?AbstractNode $expression = null;

    // For paired/block tags: inner nodes between open and close
    /** @var AbstractNode[] */
    public array $children = [];

    // For paired tags: the corresponding closing node
    public ?AntlersNode $closingPair = null;

    // Whether this is a closing tag ({{ /tag }})
    public bool $isClosingTag = false;

    // The tag name/identifier extracted during document parsing
    public string $name = '';

    // Whether this node is a comment {{# ... #}}
    public bool $isComment = false;
}
