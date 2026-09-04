<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

/**
 * The `??` operator: falls back to the right side whenever the left side is
 * falsy, using the same truthiness rules as `{{ if }}`.
 *
 * For a fallback on null only, see NullCoalesceNode (`???`).
 */
final class TruthyCoalesceNode extends AbstractNode
{
    public function __construct(
        public AbstractNode $left,
        public AbstractNode $right,
    ) {}
}
