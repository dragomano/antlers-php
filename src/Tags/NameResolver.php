<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

final readonly class NameResolver
{
    public function __construct(private TagRegistry $tags) {}

    public function isTag(string $name): bool
    {
        return $this->tags->has(explode(':', $name, 2)[0]);
    }
}
