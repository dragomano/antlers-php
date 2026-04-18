<?php

declare(strict_types=1);

namespace Bugo\Antlers;

final class GuardPolicy
{
    /**
     * @param list<string> $variables
     * @param list<string> $tags
     * @param list<string> $modifiers
     */
    public function __construct(
        public array $variables = [],
        public array $tags = [],
        public array $modifiers = [],
    ) {
        $this->variables = $this->normalizeRules($variables);
        $this->tags      = $this->normalizeRules($tags);
        $this->modifiers = $this->normalizeRules($modifiers);
    }

    public function guardsVariable(string $path): bool
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return false;
        }

        foreach ($this->variables as $guarded) {
            if ($guarded === $path
                || str_starts_with($path, $guarded . '.')
                || str_starts_with($path, $guarded . '[')
            ) {
                return true;
            }
        }

        return false;
    }

    public function guardsTag(string $name): bool
    {
        return in_array(trim($name), $this->tags, true);
    }

    public function guardsModifier(string $name): bool
    {
        return in_array(trim($name), $this->modifiers, true);
    }

    /**
     * @param list<string> $rules
     * @return list<string>
     */
    private function normalizeRules(array $rules): array
    {
        $normalized = array_values(array_filter(array_map(
            trim(...),
            $rules,
        ), static fn(string $rule): bool => $rule !== ''));

        return array_values(array_unique(array_map($this->normalizePath(...), $normalized)));
    }

    private function normalizePath(string $path): string
    {
        return str_replace(':', '.', trim($path));
    }
}
