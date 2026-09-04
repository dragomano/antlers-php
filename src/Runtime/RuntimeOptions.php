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

    public GuardPolicy $guardPolicy;

    public MarkdownRendererInterface $markdownRenderer;

    public function __construct()
    {
        $this->guardPolicy      = new GuardPolicy();
        $this->markdownRenderer = new CommonMarkRenderer();
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
