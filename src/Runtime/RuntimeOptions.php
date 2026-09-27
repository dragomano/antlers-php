<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;
use Bugo\Antlers\Support\CommonMarkRenderer;
use Bugo\Antlers\Support\MarkdownRendererInterface;

final class RuntimeOptions
{
    public bool $strict = false;

    public bool $debug = false;

    /**
     * Whether {{ obj.method }} may call a public method. Off by default, because
     * a zero-argument call is code execution driven by a template: commit(),
     * flush() and save() are all reachable that way.
     */
    public bool $allowObjectMethodCalls = false;

    public GuardPolicy $guardPolicy;

    private ?MarkdownRendererInterface $markdownRenderer = null;

    public function __construct()
    {
        $this->guardPolicy = new GuardPolicy();
    }

    public function markdownRenderer(): MarkdownRendererInterface
    {
        return $this->markdownRenderer ??= new CommonMarkRenderer();
    }

    public function setMarkdownRenderer(MarkdownRendererInterface $renderer): void
    {
        $this->markdownRenderer = $renderer;
    }

    /**
     * Applies the lenient/strict policy to a runtime failure. Strict mode
     * surfaces it; lenient mode returns the fallback so rendering continues.
     *
     * Every place that has to decide "report or carry on" goes through here, so
     * the answer lives in one spot instead of being reinvented per call site.
     *
     * @template T
     * @param  T $fallback
     * @return T
     */
    public function fail(string $reason, mixed $fallback = ''): mixed
    {
        if ($this->strict) {
            throw new AntlersRuntimeException($reason);
        }

        return $fallback;
    }

    /**
     * Runs a fragment that must not report failures and restores the policy
     * afterwards. The flag lives here, so the only place that turns it off
     * temporarily is the object that owns it.
     *
     * @template T
     * @param  callable(): T $evaluate
     * @return T
     */
    public function withoutStrict(callable $evaluate): mixed
    {
        $previous = $this->strict;

        $this->strict = false;

        try {
            return $evaluate();
        } finally {
            $this->strict = $previous;
        }
    }
}
