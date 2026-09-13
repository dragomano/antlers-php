<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\LiteralNode;
use Bugo\Antlers\Runtime\NodeProcessor;
use Bugo\Antlers\Runtime\ValueCoercion;
use Bugo\Antlers\Runtime\ValueResult;
use LogicException;

final class CoreTags
{
    public static function register(TagRegistry $registry): void
    {
        IterationTags::register($registry);
        CompositionTags::register($registry);
        ContentTags::register($registry);
        ScopeTags::register($registry);
    }

    /** @param list<string> $names */
    public static function registerNames(TagRegistry $registry, array $names): void
    {
        foreach ($names as $name) {
            $handler = match ($name) {
                'foreach'   => self::foreachTag(...),
                'partial'   => self::partialTag(...),
                'layout'    => self::layoutTag(...),
                'section'   => self::sectionTag(...),
                'yield'     => self::yieldTag(...),
                'slot'      => self::slotTag(...),
                'stack'     => self::stackTag(...),
                'push'      => self::pushTag(...),
                'prepend'   => self::prependTag(...),
                'once'      => self::onceTag(...),
                'markdown'  => self::markdownTag(...),
                'loop'      => self::loopTag(...),
                'switch'    => self::switchTag(...),
                'scope'     => self::scopeTag(...),
                'dump'      => self::dumpTag(...),
                'svg'       => self::svgTag(...),
                'increment' => self::incrementTag(...),
                default     => throw new LogicException(sprintf('Unknown core tag: "%s"', $name)),
            };

            $registry->register($name, $handler);
        }
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
            $processor->renderIterable(
                $array->value,
                self::trimBoundaryWhitespace($children),
                $valueAlias,
                $keyAlias,
            ),
            "\r\n",
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function partialTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children = [],
    ): string|bool {
        $paths = self::partialPaths($params, $method);
        if ($paths === []) {
            return $method === 'exists' ? false : '';
        }

        $resolved = self::firstExistingTemplatePath($processor, $paths);
        $exists   = $resolved !== null;
        $slotData = self::slotData($processor, $data, $children);
        $fallback = self::fallbackTemplatePath($processor, $paths);

        return match ($method) {
            'exists'    => $exists,
            'if_exists' => $exists
                ? $processor->renderTemplateFile($resolved, self::partialData($params, $data, $slotData))
                : '',
            default     => $fallback !== ''
                ? $processor->renderTemplateFile($fallback, self::partialData($params, $data, $slotData))
                : $processor->fail(sprintf('Partial not found: "%s"', implode('", "', $paths))),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function layoutTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $paths = self::layoutPaths($params, $method);
        if ($paths === []) {
            return $processor->renderFragment($children, $data);
        }

        $slotData = self::slotData($processor, $data, $children);

        $layoutData = array_merge(
            $data,
            self::layoutData($params),
            $slotData,
            ['template_content' => self::string($slotData['slot'])],
        );

        return $processor->renderTemplateFile(self::fallbackTemplatePath($processor, $paths), $layoutData);
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
    private static function slotTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $name = self::sectionName($params, $method);

        $content = $name === null
            ? self::string($data['slot'] ?? '')
            : self::slotContent($data, $name);

        return $content !== '' ? $content : $processor->renderFragment($children, $data);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function stackTag(
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

        $content = $processor->yieldStack($name);

        return $content !== '' ? $content : $processor->renderFragment($children, $data);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function pushTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        return self::storeStackContent($params, $data, $processor, $method, $children);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function prependTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        return self::storeStackContent($params, $data, $processor, $method, $children, true);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function onceTag(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
    ): string {
        $key = self::sectionName($params, $method);

        return $processor->renderOnce(
            $key,
            static fn(): string => $processor->renderFragment($children, $data),
        );
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

        if ($method === 'indent') {
            $content = self::trimSharedIndentation($content);
        }

        return $processor->markdownRenderer()->render($content);
    }

    /**
     * Removes the indentation an indented {{ markdown:indent }} block inherits
     * from the surrounding template. Without it CommonMark would read the body
     * as an indented code block.
     */
    private static function trimSharedIndentation(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));

        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        $indent = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            preg_match('/^[ \t]*/', $line, $matches);

            $lineIndent = strlen($matches[0] ?? '');
            $indent     = $indent === null ? $lineIndent : min($indent, $lineIndent);
        }

        if ($indent === null || $indent === 0) {
            return implode("\n", $lines);
        }

        return implode("\n", array_map(
            static fn(string $line): string => trim($line) === '' ? '' : substr($line, $indent),
            $lines,
        ));
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
            return $processor->fail('Loop tag requires "times" or "to".');
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
            return $processor->fail('Scope tag requires a name.');
        }

        return $processor->renderFragment($children, array_merge($data, [$name => $data]));
    }

    /**
     * Debug helper: dumps the current scope, or an explicit value.
     *
     * Output is suppressed unless debug mode is on, mirroring Statamic's
     * APP_DEBUG gate. Use force="true" to dump regardless.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     */
    private static function dumpTag(array $params, array $data, NodeProcessor $processor): string
    {
        if (! $processor->isDebugEnabled() && ! self::bool($params['force'] ?? false)) {
            return '';
        }

        $exported = var_export($params['value'] ?? $params['var'] ?? $data, true);

        return '<pre>' . htmlspecialchars($exported, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</pre>';
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     */
    private static function svgTag(array $params, array $data, NodeProcessor $processor): string
    {
        $path = self::svgPath($params);
        if (! is_string($path->value) || $path->value === '') {
            return $processor->fail('Svg tag requires a "src" parameter.');
        }

        $resolved = $processor->resolveTemplateTagPath($path->value);
        if ($resolved === '' || ! is_file($resolved)) {
            return $processor->fail(sprintf('Svg file not found: "%s"', $path->value));
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
     * @return list<string>
     */
    private static function layoutPaths(array $params, string $method): array
    {
        $path = self::parameterValue($params, 'src', 'path', 'name', 'layout');
        if (is_string($path->value) && $path->value !== '') {
            return self::templatePathCandidates($path->value);
        }

        return $method !== 'index'
            ? self::templatePathCandidates($method)
            : [];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param array{slot: string, __slots: array<string, string>} $slotData
     * @return array<string, mixed>
     */
    private static function partialData(array $params, array $data, array $slotData): array
    {
        unset($params['src'], $params['path'], $params['name']);

        /** @var array<string, mixed> $merged */
        $merged = array_merge($data, $slotData, $params);

        return $merged;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function layoutData(array $params): array
    {
        unset($params['src'], $params['path'], $params['name'], $params['layout']);

        return $params;
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
            $resolved = $processor->resolveTemplateTagPath($path);
            if ($resolved !== '' && is_file($resolved)) {
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

        return $processor->resolveTemplateTagPath($paths[0]);
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

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    private static function storeStackContent(
        array $params,
        array $data,
        NodeProcessor $processor,
        string $method,
        array $children,
        bool $prepend = false,
    ): string {
        $name = self::sectionName($params, $method);
        if ($name === null) {
            return '';
        }

        $processor->storeStack($name, $processor->renderFragment($children, $data), $prepend);

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     * @return array{slot: string, __slots: array<string, string>}
     */
    private static function slotData(NodeProcessor $processor, array $data, array $children): array
    {
        if ($children === []) {
            return ['slot' => '', '__slots' => []];
        }

        $slots = $processor->renderSlots($children, $data);

        return [
            'slot'    => $slots['default'],
            '__slots' => $slots['named'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function slotContent(array $data, string $name): string
    {
        if (! is_array($data['__slots'] ?? null)) {
            return '';
        }

        return self::string($data['__slots'][$name] ?? '');
    }

    private static function string(mixed $value): string
    {
        return ValueCoercion::toString($value);
    }

    private static function int(mixed $value): int
    {
        return ValueCoercion::toInt($value);
    }

    /**
     * Quoted parameters are the common case in HTML-ish templates, so the
     * usual falsy spellings have to be recognised as strings.
     */
    private static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], strict: true);
        }

        return (bool) $value;
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
