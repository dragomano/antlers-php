<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Nodes\AbstractNode;

interface TagRuntime
{
    /**
     * @param AbstractNode[] $children
     * @param array<string, mixed> $data
     */
    public function renderFragment(array $children, array $data = []): string;

    /** @param array<string, mixed> $scope */
    public function resolvePathValue(string $path, array $scope): mixed;

    /** @param array<string, mixed> $scope */
    public function pathExists(string $path, array $scope): bool;

    public function fail(string $reason, mixed $fallback = ''): mixed;
}
