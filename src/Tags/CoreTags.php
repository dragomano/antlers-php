<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\LiteralNode;
use Bugo\Antlers\Runtime\NodeProcessor;
use Bugo\Antlers\Runtime\ValueResult;
use Bugo\Antlers\Support\MarkdownRenderer;

final class CoreTags
{
    public static function register(TagRegistry $registry): void
    {
        $registry->register('foreach', self::foreachTag(...));
        $registry->register('partial', self::partialTag(...));
        $registry->register('section', self::sectionTag(...));
        $registry->register('yield', self::yieldTag(...));
        $registry->register('markdown', self::markdownTag(...));
        $registry->register('loop', self::loopTag(...));
        $registry->register('switch', self::switchTag(...));
        $registry->register('scope', self::scopeTag(...));
        $registry->register('dump', self::dumpTag(...));
        $registry->register('svg', self::svgTag(...));
        $registry->register('increment', self::incrementTag(...));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function foreachTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $array = self::resolveForeachItems($params, $data, $processor, $method);

        if ($array->value === null) {
            return '';
        }

        [$keyAlias, $valueAlias] = self::parseForeachAliases($params['as'] ?? null);
        $limit = isset($params['limit']) ? max(0, self::int($params['limit'])) : null;

        if ($limit !== null && is_iterable($array->value)) {
            $array = new ValueResult(self::sliceIterable($array->value, $limit));
        }

        return trim(
            $processor->renderIterable($array->value, self::trimBoundaryWhitespace($children), $valueAlias, $keyAlias),
            "\r\n",
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     */
    private static function partialTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
    ): string|bool {
        $paths = self::partialPaths($params, $method);
        if ($paths === []) {
            return $method === 'exists' ? false : '';
        }

        $resolved = self::firstExistingTemplatePath($processor, $paths);
        $exists   = $resolved !== null;

        return match ($method) {
            'exists'    => $exists,
            'if_exists' => $exists ? $processor->renderTemplateFile($resolved, self::partialData($params, $data)) : '',
            default     => $processor->renderTemplateFile(self::fallbackTemplatePath($processor, $paths), self::partialData($params, $data)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function sectionTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $name = self::sectionName($params, $method);
        if ($name === null) {
            return '';
        }

        $content = $processor->renderFragment($children, $data);

        $processor->storeSection($name, $content, (bool) ($params['append'] ?? false));

        return '';
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function yieldTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $name = self::sectionName($params, $method);
        if ($name === null) {
            return $children === [] ? '' : $processor->renderFragment($children, $data);
        }

        $content = $processor->yieldSection($name);

        return $content !== '' ? $content : $processor->renderFragment($children, $data);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function markdownTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $content = $children !== []
            ? $processor->renderFragment($children, $data)
            : self::string($params['text'] ?? $params['content'] ?? '');

        return MarkdownRenderer::render($content, $method === 'indent');
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function loopTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $times = isset($params['times']) ? self::int($params['times']) : null;
        $from  = isset($params['from']) ? self::int($params['from']) : (isset($params['start']) ? self::int($params['start']) : 1);
        $to    = isset($params['to']) ? self::int($params['to']) : (isset($params['end']) ? self::int($params['end']) : null);

        if ($times !== null) {
            $to = $from + $times - 1;
        }

        if (isset($params['count']) && $to === null) {
            $to = $from + self::int($params['count']) - 1;
        }

        if ($method !== 'index' && is_numeric($method)) {
            $from = 1;
            $to   = self::int($method);
        }

        if ($to === null) {
            throw new AntlersRuntimeException('Loop tag requires "times" or "to".');
        }

        return $processor->renderCounterLoop($from, $to, $children);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     */
    private static function switchTag(array $params, array $data, NodeProcessor $processor): string
    {
        $values = self::switchValues($params);
        $values = array_values(array_filter($values, static fn(mixed $value): bool => $value !== ''));
        if ($values === []) {
            return '';
        }

        $strValues = array_map(self::string(...), $values);
        $name      = self::string($params['name'] ?? implode('|', $strValues));

        return self::string($processor->nextSwitchValue($name, $values));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function scopeTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $name = self::sectionName($params, $method);
        if ($name === null) {
            return '';
        }

        return $processor->renderFragment($children, array_merge($data, [$name => $data]));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     */
    private static function dumpTag(array $params, array $data): string
    {
        return '<pre>' . var_export($params['value'] ?? $params['var'] ?? $data, true) . '</pre>';
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     */
    private static function svgTag(array $params, array $data, NodeProcessor $processor): string
    {
        $path = self::svgPath($params);
        if (! is_string($path->value) || $path->value === '') {
            return '';
        }

        $resolved = $processor->resolveTemplatePath($path->value);
        if (! is_file($resolved)) {
            return '';
        }

        $contents = file_get_contents($resolved);

        return $contents === false ? '' : $contents;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     */
    private static function incrementTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
    ): int {
        $name = self::string($params['name'] ?? ($method !== 'index' ? $method : 'default'));
        $from = isset($params['from']) ? self::int($params['from']) : 1;
        $step = isset($params['by']) ? self::int($params['by']) : 1;

        return $processor->nextIncrement($name, $from, $step);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parseForeachAliases(mixed $as): array
    {
        if (! is_string($as) || $as === '') {
            return ['key', 'value'];
        }

        $parts = array_map(trim(...), explode('|', $as, 2));

        return [$parts[0] ?: 'key', $parts[1] ?? 'value'];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function sliceIterable(mixed $items, int $limit): array
    {
        if ($limit === 0 || ! is_iterable($items)) {
            return [];
        }

        return array_slice(is_array($items) ? $items : iterator_to_array($items), 0, $limit, true);
    }

    /**
     * @param AbstractNode[] $children
     * @return AbstractNode[]
     */
    private static function trimBoundaryWhitespace(array $children): array
    {
        while ($children !== [] && self::isWhitespaceLiteral($children[0])) {
            array_shift($children);
        }

        while ($children !== [] && self::isWhitespaceLiteral($children[array_key_last($children)])) {
            array_pop($children);
        }

        return array_values($children);
    }

    private static function isWhitespaceLiteral(AbstractNode $node): bool
    {
        return $node instanceof LiteralNode && trim($node->content) === '';
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private static function partialPaths(array $params, string $method): array
    {
        $path = self::svgPath($params);
        if (is_string($path->value) && $path->value !== '') {
            return self::templatePathCandidates($path->value);
        }

        return match ($method) {
            'index',
            'exists',
            'if_exists' => [],
            default     => self::templatePathCandidates($method),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function partialData(array $params, array $data): array
    {
        unset($params['src'], $params['path'], $params['name']);

        return array_merge($data, $params);
    }

    /**
     * @return list<string>
     */
    private static function templatePathCandidates(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [];
        }

        $candidates = [$path];

        $basename = basename($path);
        if (! str_contains($basename, '.')) {
            $candidates[] = $path . '.antlers.html';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param list<string> $paths
     */
    private static function firstExistingTemplatePath(NodeProcessor $processor, array $paths): ?string
    {
        foreach ($paths as $path) {
            $resolved = $processor->resolveTemplatePath($path);
            if (is_file($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param list<string> $paths
     */
    private static function fallbackTemplatePath(NodeProcessor $processor, array $paths): string
    {
        $resolved = self::firstExistingTemplatePath($processor, $paths);
        if ($resolved !== null) {
            return $resolved;
        }

        return $processor->resolveTemplatePath($paths[0]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function sectionName(array $params, string $method): ?string
    {
        $name = self::parameterValue($params, 'name', 'section');
        if (is_string($name->value) && $name->value !== '') {
            return $name->value;
        }

        return $method !== 'index' ? $method : null;
    }

    private static function string(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     */
    private static function resolveForeachItems(array $params, array $data, NodeProcessor $processor, string $method): ValueResult
    {
        if (array_key_exists('array', $params)) {
            if (is_string($params['array']) && $processor->pathExists($params['array'], $data)) {
                return new ValueResult($processor->resolvePathValue($params['array'], $data));
            }

            return new ValueResult($params['array']);
        }

        if ($method !== 'index') {
            return new ValueResult($processor->resolvePathValue($method, $data));
        }

        return new ValueResult(null);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<mixed>
     */
    private static function switchValues(array $params): array
    {
        $between = self::parameterValue($params, 'between', 'values', 'in');
        if ($between->value === null) {
            return [];
        }

        return is_array($between->value)
            ? array_values($between->value)
            : array_map(trim(...), explode('|', self::string($between->value)));
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function svgPath(array $params): ValueResult
    {
        return self::parameterValue($params, 'src', 'path', 'name');
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function parameterValue(array $params, string ...$keys): ValueResult
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $params)) {
                return new ValueResult($params[$key]);
            }
        }

        return new ValueResult(null);
    }
}
