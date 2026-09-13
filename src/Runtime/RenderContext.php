<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Closure;

final readonly class RenderContext
{
    public Scope $scope;

    public RenderState $state;

    public RecursionState $recursion;

    /** @param array<string, mixed> $globals */
    public function __construct(array $globals = [])
    {
        $this->scope     = new Scope($globals);
        $this->state     = new RenderState();
        $this->recursion = new RecursionState();
    }

    /**
     * @param array<string, mixed> $data
     * @param Closure(): string $renderer
     */
    public function renderFrame(array $data, Closure $renderer): string
    {
        $this->scope->push($data);

        try {
            return $renderer();
        } finally {
            $this->scope->pop();
        }
    }
}
