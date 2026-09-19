<?php

declare(strict_types=1);

namespace Bugo\Antlers;

final readonly class GuardPolicy
{
    /** @var list<string> */
    public array $variables;

    /** @var list<string> */
    public array $tags;

    /** @var list<string> */
    public array $modifiers;

    /**
     * @param list<string> $variables
     * @param list<string> $tags
     * @param list<string> $modifiers
     */
    public function __construct(
        array $variables = [],
        array $tags = [],
        array $modifiers = [],
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
            if ($guarded === $path || str_starts_with($path, $guarded . '.')) {
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
     * Strips guarded descendants out of a resolved value so field-level rules
     * survive whole-container access ({{ user }}, paired blocks, foreach, pluck),
     * not just the exact-path lookup that guardsVariable() already blocks.
     */
    public function redact(string $path, mixed $value): mixed
    {
        if ($this->variables === []) {
            return $value;
        }

        $suffixes = $this->descendantSuffixes($this->normalizePath($path));
        if ($suffixes === []) {
            return $value;
        }

        return $this->applyRedactions($value, $suffixes);
    }

    /**
     * Paths of guarded fields relative to $path, e.g. for path "user" and rule
     * "user.password" the suffix is "password".
     *
     * @return list<string>
     */
    private function descendantSuffixes(string $path): array
    {
        $prefix = $path . '.';

        $suffixes = [];
        foreach ($this->variables as $guarded) {
            if (str_starts_with($guarded, $prefix)) {
                $suffixes[] = substr($guarded, strlen($prefix));
            }
        }

        return $suffixes;
    }

    /**
     * @param list<string> $suffixes
     */
    private function applyRedactions(mixed $value, array $suffixes): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        // All-integer keys (gaps allowed) mean a collection: guard the same
        // field in every element, matching how paired blocks classify values.
        if ($value !== [] && array_filter(array_keys($value), is_string(...)) === []) {
            return array_map(
                fn(mixed $item): mixed => $this->applyRedactions($item, $suffixes),
                $value,
            );
        }

        foreach ($suffixes as $suffix) {
            $value = $this->removeSuffix($value, $suffix);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function removeSuffix(array $value, string $suffix): array
    {
        $dot = strpos($suffix, '.');
        if ($dot === false) {
            unset($value[$suffix]);

            return $value;
        }

        $head = substr($suffix, 0, $dot);
        if (! array_key_exists($head, $value)) {
            return $value;
        }

        // Union keeps every original key (unlike array_merge) while overriding
        // only $head, and avoids a MixedAssignment into an array offset.
        return [$head => $this->applyRedactions($value[$head], [substr($suffix, $dot + 1)])] + $value;
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
        $path = str_replace(':', '.', trim($path));

        // Fold subscripts into dot notation so a leaf rule like "user.password"
        // also guards "user['password']", "user[\"password\"]" and "user[0]".
        return (string) preg_replace('/\[\s*[\'"]?(.*?)[\'"]?\s*]/', '.$1', $path);
    }
}
