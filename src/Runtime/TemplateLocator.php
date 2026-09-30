<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

/**
 * Resolves template paths and holds the boundary every template lookup is
 * contained in. There is no implicit root: a lookup can only reach the
 * directory of the template being rendered and the configured view paths, so
 * `{{ partial src="src/Engine.php" }}` cannot turn the working directory into
 * a template tree.
 *
 * A top-level `Engine::renderFile()` is a direct call rather than a template
 * action, so it goes through `resolveEntryTemplatePath()`: with nothing
 * configured the entry file's own directory becomes the root for that render
 * (via `pushTemplate()`), and once any root exists the boundary applies to it
 * like everywhere else.
 */
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

    public function resolveEntryTemplatePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if ($this->templateSearchRoots() !== []) {
            return $this->resolveTemplatePath($path);
        }

        if (! $this->isAbsolutePath($path)) {
            return '';
        }

        return $this->normalizeAbsolutePath($path) ?? '';
    }

    public function resolveTemplatePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $roots = $this->templateSearchRoots();

        if ($roots === []) {
            return '';
        }

        if ($this->isAbsolutePath($path)) {
            return $this->absolutePathWithinRoots($path, $roots) ?? '';
        }

        return $this->firstExistingSafeTemplatePath($roots, [$path])
            ?? ($this->resolvePathWithinRoot($roots[0], $path) ?? '');
    }

    public function resolveTemplateTagPath(string $path): string
    {
        if ($path === '' || $this->isAbsolutePath($path)) {
            return '';
        }

        $roots = $this->templateSearchRoots();

        if ($roots === []) {
            return '';
        }

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

    /** @return list<string> */
    private function templateSearchRoots(): array
    {
        $roots = [];

        if ($this->templatePathStack !== []) {
            $roots[] = $this->templatePathStack[count($this->templatePathStack) - 1];
        }

        foreach ($this->viewPaths as $templateRoot) {
            $roots[] = $templateRoot;
        }

        return array_values(array_unique($roots));
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

    private function normalizeAbsolutePath(string $path): ?string
    {
        $real = realpath($path);

        return $real !== false ? $real : $this->normalizePath($path);
    }

    /** @param list<string> $roots */
    private function absolutePathWithinRoots(string $path, array $roots): ?string
    {
        $normalized = $this->normalizeAbsolutePath($path);

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
