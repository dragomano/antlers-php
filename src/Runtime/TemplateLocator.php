<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

final class TemplateLocator
{
    /** @var string[] */
    private array $templatePathStack = [];

    /** @var string[] */
    private array $viewPaths = [];

    /** @param string|string[] $paths */
    public function setViewPaths(string|array $paths): void
    {
        $paths = is_array($paths) ? $paths : [$paths];

        $this->viewPaths = array_values(array_filter(array_map(
            trim(...),
            $paths,
        ), static fn(string $path): bool => $path !== ''));
    }

    public function pushTemplate(string $path): void
    {
        $this->templatePathStack[] = dirname($path);
    }

    public function popTemplate(): void
    {
        array_pop($this->templatePathStack);
    }

    public function currentTemplateIdentifier(): string
    {
        if ($this->templatePathStack === []) {
            return '__inline__';
        }

        return $this->templatePathStack[count($this->templatePathStack) - 1];
    }

    public function resolveTemplatePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        $roots = $this->templateSearchRoots();

        if ($this->isAbsolutePath($path)) {
            if (! $this->hasConfiguredViewPaths()) {
                return $path;
            }

            return $this->absolutePathWithinRoots($path, $roots) ?? '';
        }

        $resolved = $this->hasConfiguredViewPaths()
            ? $this->firstExistingSafeTemplatePath($roots, [$path])
            : $this->firstExistingTemplatePath($roots, [$path]);

        if ($resolved !== null) {
            return $resolved;
        }

        if (! $this->hasConfiguredViewPaths()) {
            return $this->joinPath($roots[0], $path);
        }

        return $this->resolvePathWithinRoot($roots[0], $path) ?? '';
    }

    public function resolveTemplateTagPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if ($this->isAbsolutePath($path)) {
            return '';
        }

        $roots    = $this->templateSearchRoots();
        $resolved = $this->firstExistingSafeTemplatePath($roots, [$path]);

        if ($resolved !== null) {
            return $resolved;
        }

        return $this->resolvePathWithinRoot($roots[0], $path) ?? '';
    }

    public function resolveViewPath(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return $name;
        }

        $candidates = [$name];

        if (! str_contains(basename($name), '.')) {
            $candidates[] = $name . '.antlers.html';
        }

        $viewRoots = array_values($this->viewPaths);
        $resolved  = $this->firstExistingSafeTemplatePath($viewRoots, $candidates);

        if ($resolved !== null) {
            return $resolved;
        }

        if ($viewRoots !== []) {
            return $this->resolvePathWithinRoot($viewRoots[0], $candidates[0]) ?? '';
        }

        return $this->resolveTemplatePath($candidates[0]);
    }

    /** @return non-empty-list<string> */
    private function templateSearchRoots(): array
    {
        $roots = [];

        if ($this->templatePathStack !== []) {
            $roots[] = $this->templatePathStack[count($this->templatePathStack) - 1];
        }

        foreach ($this->viewPaths as $templateRoot) {
            $roots[] = $templateRoot;
        }

        if ($roots === []) {
            $roots[] = (string) getcwd();
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param list<string> $roots
     * @param list<string> $candidates
     */
    private function firstExistingTemplatePath(array $roots, array $candidates): ?string
    {
        foreach ($roots as $root) {
            foreach ($candidates as $candidate) {
                $resolved = $this->joinPath($root, $candidate);
                if (is_file($resolved)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $roots
     * @param list<string> $candidates
     */
    private function firstExistingSafeTemplatePath(array $roots, array $candidates): ?string
    {
        foreach ($roots as $root) {
            foreach ($candidates as $candidate) {
                $resolved = $this->resolvePathWithinRoot($root, $candidate);
                if ($resolved !== null && is_file($resolved)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /** @param list<string> $roots */
    private function absolutePathWithinRoots(string $path, array $roots): ?string
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === null) {
            return null;
        }

        foreach ($roots as $root) {
            if ($this->isPathWithinRoot($normalized, $root)) {
                return $normalized;
            }
        }

        return null;
    }

    private function hasConfiguredViewPaths(): bool
    {
        return $this->viewPaths !== [];
    }

    private function joinPath(string $root, string $path): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private function resolvePathWithinRoot(string $root, string $path): ?string
    {
        $root     = rtrim($root, DIRECTORY_SEPARATOR);
        $rootReal = realpath($root);

        if ($rootReal === false) {
            return null;
        }

        $candidate  = $rootReal . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        $normalized = $this->normalizePath($candidate);

        if ($normalized === null) {
            return null;
        }

        return $this->isPathWithinRoot($normalized, $rootReal) ? $normalized : null;
    }

    private function isPathWithinRoot(string $path, string $root): bool
    {
        $rootReal = realpath(rtrim($root, DIRECTORY_SEPARATOR));

        if ($rootReal === false) {
            return false;
        }

        return $path === $rootReal || str_starts_with($path, $rootReal . DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1);
    }

    private function normalizePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            $prefix = substr($path, 0, 2);
            $path   = substr($path, 2);
        } else {
            $prefix = '';
        }

        $segments = explode('/', ltrim($path, '/'));
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($resolved === []) {
                    return null;
                }

                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        $normalized = $prefix . '/' . implode('/', $resolved);

        return str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }
}
