<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Nodes\AssignmentNode;
use Bugo\Antlers\Nodes\ConditionNode;
use Bugo\Antlers\Nodes\GatekeeperNode;
use Bugo\Antlers\Nodes\LiteralNode;
use Bugo\Antlers\Nodes\LoopNode;
use Bugo\Antlers\Nodes\ModifierChainNode;
use Bugo\Antlers\Nodes\NullCoalesceNode;
use Bugo\Antlers\Nodes\SequenceNode;
use Bugo\Antlers\Nodes\SetNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Nodes\TernaryNode;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Parser\DocumentParser;
use Bugo\Antlers\Parser\LanguageParser;
use Bugo\Antlers\Tags\TagRegistry;
use Traversable;

/**
 * Stage 4: Walks the parsed AST, evaluates nodes, and produces the final string output.
 */
final class NodeProcessor
{
    /** @var array<string, mixed> */
    private array $globalData = [];

    /** @var array<int, array<string, mixed>> Scope stack; each entry is a data frame */
    private array $scopeStack = [];

    /** @var array<string, string> */
    private array $sections = [];

    /** @var array<string, string[]> */
    private array $stacks = [];

    /** @var array<string, true> */
    private array $onceKeys = [];

    /** @var array<string, int> */
    private array $increments = [];

    /** @var array<string, int> */
    private array $switches = [];

    /** @var string[] */
    private array $templatePathStack = [];

    /** @var string[] */
    private array $templateRenderStack = [];

    /** @var string[] */
    private array $viewPaths = [];

    /** @var list<array{name: string, method: string, line: int, signature: string}> */
    private array $tagContextStack = [];

    public function __construct(
        private readonly DocumentParser $documentParser,
        private readonly ExpressionEvaluator $evaluator,
        private readonly ConditionProcessor $conditions,
        private readonly TagRegistry $tags,
        private readonly PathDataManager $paths,
        private readonly LanguageParser $parser,
        private readonly RuntimeOptions $options,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function setGlobalData(array $data): void
    {
        $this->globalData = $data;
    }

    /**
     * @param string|string[] $paths
     */
    public function setViewPaths(string|array $paths): void
    {
        $paths = is_array($paths) ? $paths : [$paths];

        $this->viewPaths = array_values(array_filter(array_map(
            trim(...),
            $paths,
        ), static fn(string $path): bool => $path !== ''));
    }

    /**
     * Render a list of nodes with the given data scope.
     *
     * @param AbstractNode[] $nodes
     * @param array<string, mixed> $data
     */
    public function reduce(array $nodes, array $data = []): string
    {
        $isRootRender = $this->scopeStack === [];
        if ($isRootRender) {
            $this->sections   = [];
            $this->stacks     = [];
            $this->onceKeys   = [];
            $this->increments = [];
            $this->switches   = [];
        }

        $this->pushScope($data);

        $output = $this->processNodes($nodes);

        $this->popScope();

        return $output;
    }

    /**
     * @param AbstractNode[] $nodes
     */
    private function processNodes(array $nodes): string
    {
        $output = '';

        foreach ($nodes as $node) {
            $output .= $this->processNode($node, $this->currentScope());
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processNode(AbstractNode $node, array $scope): string
    {
        // Literal text — pass through unchanged
        if ($node instanceof LiteralNode) {
            return $node->content;
        }

        // Parsed typed nodes from LanguageParser
        if ($node instanceof ConditionNode) {
            return $this->processCondition($node, $scope);
        }

        if ($node instanceof LoopNode) {
            return $this->processLoop($node, $scope);
        }

        if ($node instanceof SetNode) {
            $this->processSet();

            // Update current scope frame
            $this->scopeStack[count($this->scopeStack) - 1][$node->variableName]
                = $this->evaluateNodeValue($node->value, $scope);

            return '';
        }

        if ($node instanceof AssignmentNode) {
            $result = $this->evaluateNodeResult($node->value, $scope);

            $this->writeScopeValue($node->variableName, $result->value);

            if ($node->children === []) {
                return '';
            }

            return $this->processPairedValue($result->value, $node->children);
        }

        if ($node instanceof SequenceNode) {
            $result = $this->evaluateNodeResult($node, $scope);

            $lastStatementKey = array_key_last($node->statements);
            $lastStatement    = $lastStatementKey !== null ? $node->statements[$lastStatementKey] : null;

            if ($lastStatement instanceof AssignmentNode) {
                return '';
            }

            return $this->evaluator->stringify($result->value);
        }

        if ($node instanceof TagNode) {
            return $this->processTag($node, $scope);
        }

        if ($node instanceof ModifierChainNode
            || $node instanceof TernaryNode
            || $node instanceof GatekeeperNode
            || $node instanceof NullCoalesceNode
            || $node instanceof VariableNode
        ) {
            return $this->stringifyEvaluatedNode($node, $scope);
        }

        // Raw AntlersNode — needs parsing first
        if ($node instanceof AntlersNode) {
            return $this->processRawAntlersNode($node, $scope);
        }

        // All other expression nodes
        return $this->stringifyEvaluatedNode($node, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processRawAntlersNode(AntlersNode $node, array $scope): string
    {
        if ($node->isClosingTag) {
            return '';
        }

        // Noparse block — render children as raw text
        if ($node->name === 'noparse') {
            return $this->childrenAsRaw($node->children);
        }

        // Paired variable block: {{ items }}...{{ /items }}
        // DocumentParser filled $node->children; built-in blocks (if/foreach/for) handle themselves below.
        $builtinBlocks = ['if', 'unless', 'foreach', 'for', 'cache', 'markdown'];
        if ($node->children !== [] && ! in_array($node->name, $builtinBlocks, strict: true)) {
            $parsed = $this->parser->parseNode($node);

            // Could be a paired tag in the registry
            if ($parsed instanceof TagNode && $this->tags->has($parsed->name)) {
                return $this->processNode($parsed, $scope);
            }

            if ($parsed instanceof AssignmentNode) {
                return $this->processNode($parsed, $scope);
            }

            if ($parsed instanceof VariableNode) {
                return $this->processPairedVariable($parsed->path, $node->children, $scope);
            }

            // Otherwise: paired variable loop
            return $this->processPairedVariable($node->rawContent, $node->children, $scope);
        }

        // Simple identifier that is a registered tag ({{ myTag }})
        $raw              = trim($node->rawContent);
        $isSimpleIdent    = preg_match('/^[\w.]+$/', $raw) === 1;
        if ($node->children === [] && $isSimpleIdent && $this->tags->has($node->name)) {
            return $this->processTag(new TagNode($node->name, 'index', [], [], false), $scope);
        }

        // Colon notation can represent a variable path (user:profile:name, next:value)
        // or a tag method (tag:method). Prefer the variable when it exists in scope.
        $isColonPath = preg_match('/^\w+(?::\w+|\.\w+|\[[^]]+])*$/', $raw) === 1;
        if ($isColonPath && $this->paths->has($raw, $scope)) {
            return $this->evaluator->stringify($this->resolvePathValue($raw, $scope));
        }

        // Parse the node into a typed AST node and process it
        $parsed = $this->parser->parseNode($node);

        return $this->processNode($parsed, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processCondition(ConditionNode $node, array $scope): string
    {
        $children = $this->conditions->process($node, $scope, $this->assignmentWriter());
        if ($children === []) {
            return '';
        }

        return $this->renderChildrenWithScope([], $children);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processLoop(LoopNode $node, array $scope): string
    {
        if ($node->type === 'foreach') {
            return $this->processForeach($node, $scope);
        }

        if ($node->type === 'for') {
            return $this->processFor($node, $scope);
        }

        if ($node->type === 'paired') {
            return $this->iterateItems(
                $this->resolvePathResult($node->variablePath ?? '', $scope)->value,
                $node->children,
            );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processForeach(LoopNode $node, array $scope): string
    {
        if (! $node->iterable instanceof AbstractNode) {
            return '';
        }

        $items = $this->evaluateNodeValue($node->iterable, $scope);

        if (! is_iterable($items)) {
            return '';
        }

        return $this->iterateItems($items, $node->children, $node->alias, $node->keyAlias);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processFor(LoopNode $node, array $scope): string
    {
        if (! $node->from instanceof AbstractNode || ! $node->to instanceof AbstractNode) {
            return '';
        }

        $from = $this->toInt($this->evaluateNodeValue($node->from, $scope));
        $to   = $this->toInt($this->evaluateNodeValue($node->to, $scope));

        return $this->renderCounterRange($from, $to, $node->children);
    }

    /**
     * @param AbstractNode[] $children
     */
    private function iterateItems(
        mixed $items,
        array $children,
        ?string $alias = null,
        ?string $keyAlias = null,
    ): string {
        if (! is_iterable($items)) {
            return '';
        }

        $itemArray  = $this->iterableToArray($items) ?? [];
        $itemValues = array_values($itemArray);
        $total      = count($itemArray);
        $output     = '';
        $index      = 0;

        array_walk($itemArray, function (mixed $item, int|string $key) use (
            &$children,
            &$output,
            &$itemValues,
            &$total,
            &$index,
            $alias,
            $keyAlias,
        ): void {
            $index++;

            $loopVars = [
                'count' => $index,
                'index' => $index - 1,
                'total' => $total,
                'first' => $index === 1,
                'last'  => $index === $total,
                'odd'   => $index % 2 !== 0,
                'even'  => $index % 2 === 0,
                'key'   => $key,
                'prev'  => $this->normalizeRelativeLoopItem($index > 1 ? ($itemValues[$index - 2] ?? null) : null),
                'next'  => $this->normalizeRelativeLoopItem($index < $total ? ($itemValues[$index] ?? null) : null),
            ];

            $itemScope = $this->extractItemScope($item);
            $loopVars = $itemScope !== null ? array_merge($loopVars, $itemScope) : $this->withLoopValue($loopVars, 'value', $item);

            // Named alias
            if ($alias !== null) {
                $loopVars = $this->withLoopValue($loopVars, $alias, $item);
            }

            if ($keyAlias !== null) {
                $loopVars[$keyAlias] = $key;
            }

            $output .= $this->renderChildrenWithScope($loopVars, $children);
        });

        return $output;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function normalizeRelativeLoopItem(mixed $item): ?array
    {
        if ($item === null) {
            return null;
        }

        $itemScope = $this->extractItemScope($item);
        if ($itemScope !== null) {
            return $itemScope;
        }

        return ['value' => $item];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processTag(TagNode $node, array $scope): string
    {
        if (! $this->tags->has($node->name)) {
            if ($this->options->strict) {
                throw new AntlersRuntimeException("Unknown tag: \"$node->name\"");
            }

            return '';
        }

        if ($this->options->guardPolicy->guardsTag($node->name)) {
            if ($this->options->strict) {
                throw new AntlersRuntimeException("Guarded tag: \"$node->name\"");
            }

            return '';
        }

        // Resolve parameter values
        $params = array_map(
            fn(AbstractNode $paramNode): mixed => $this->evaluateNodeValue($paramNode, $scope),
            $node->parameters,
        );

        $params = array_filter(
            $params,
            static fn(mixed $value): bool => ! $value instanceof VoidValue,
        );

        $result = $this->handleTagResult($node, $params, $scope);

        if ($result->value === null) {
            return '';
        }

        return $this->evaluator->stringify($result->value);
    }

    private function processSet(): void
    {
        // Value is set in the caller after evaluating
    }

    /**
     * Called by processRawAntlersNode when the tag turns out to be a paired variable.
     */
    /**
     * @param AbstractNode[] $children
     * @param array<string, mixed> $scope
     */
    public function processPairedVariable(string $path, array $children, array $scope): string
    {
        $value = $this->resolvePathResult($path, $scope);

        return $this->processPairedValue($value->value, $children);
    }

    /**
     * @param AbstractNode[] $children
     */
    private function processPairedValue(mixed $value, array $children): string
    {
        $items = $this->iterableToArray($value);
        if ($items !== null && $items !== []) {
            // Check if it's a list (numeric keys) or associative array
            if (array_is_list($items)) {
                return $this->iterateItems($items, $children);
            }

            // Single associative item — render with merged scope
            return $this->renderChildrenWithScope($this->normalizeScopeFrame($items), $children);
        }

        $itemScope = $this->extractItemScope($value);
        if ($itemScope !== null && $itemScope !== []) {
            return $this->renderChildrenWithScope($itemScope, $children);
        }

        if ($this->evaluator->isTruthy($value)) {
            // Scalar or object — just render children with current scope
            return $this->renderChildrenWithScope([], $children);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderTemplate(string $template, array $data = []): string
    {
        $nodes = $this->documentParser->parse($template);

        return $this->reduce($nodes, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderTemplateFile(string $path, array $data = []): string
    {
        $resolved = $this->resolveTemplatePath($path);
        if ($resolved === '') {
            throw new AntlersRuntimeException("Template file is outside the configured template roots: $path");
        }

        if (! is_file($resolved)) {
            throw new AntlersRuntimeException("Template file not found: $resolved");
        }

        if (in_array($resolved, $this->templateRenderStack, true)) {
            throw new AntlersRuntimeException("Recursive template rendering detected: $resolved");
        }

        $this->templateRenderStack[] = $resolved;
        $this->templatePathStack[]   = dirname($resolved);

        try {
            return $this->renderTemplate((string) file_get_contents($resolved), $data);
        } finally {
            array_pop($this->templatePathStack);
            array_pop($this->templateRenderStack);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderView(string $name, array $data = []): string
    {
        $resolved = $this->resolveViewPath($name);
        if (! is_file($resolved)) {
            throw new AntlersRuntimeException("Template view not found: $name");
        }

        return $this->renderTemplateFile($resolved, $data);
    }

    /**
     * @param AbstractNode[] $children
     * @param array<string, mixed> $data
     */
    public function renderFragment(array $children, array $data = []): string
    {
        return $this->reduce($children, $data);
    }

    /**
     * @param AbstractNode[] $children
     * @param array<string, mixed> $data
     * @return array{default: string, named: array<string, string>}
     */
    public function renderSlots(array $children, array $data = []): array
    {
        $defaultChildren = [];
        $namedSlots      = [];

        foreach ($children as $child) {
            $slot = $this->resolveSlotDefinition($child, $data);

            if ($slot === null) {
                $defaultChildren[] = $child;

                continue;
            }

            $namedSlots[$slot['name']] = ($namedSlots[$slot['name']] ?? '')
                . $this->renderFragment($slot['children'], $data);
        }

        return [
            'default' => $this->renderFragment($defaultChildren, $data),
            'named'   => $namedSlots,
        ];
    }

    /**
     * @param AbstractNode[] $children
     */
    public function renderIterable(
        mixed $items,
        array $children,
        ?string $alias = null,
        ?string $keyAlias = null,
    ): string {
        return $this->iterateItems($items, $children, $alias, $keyAlias);
    }

    /**
     * @param AbstractNode[] $children
     */
    public function renderCounterLoop(int $from, int $to, array $children): string
    {
        return $this->renderCounterRange($from, $to, $children);
    }

    /**
     * @param AbstractNode[] $children
     */
    private function renderCounterRange(int $from, int $to, array $children): string
    {
        $output = '';
        $step   = $from <= $to ? 1 : -1;
        $total  = abs($to - $from) + 1;
        $index  = 0;

        for ($i = $from; $step > 0 ? $i <= $to : $i >= $to; $i += $step) {
            $index++;

            $loopVars = [
                'count' => $index,
                'index' => $index - 1,
                'total' => $total,
                'first' => $index === 1,
                'last'  => $index === $total,
                'odd'   => $index % 2 !== 0,
                'even'  => $index % 2 === 0,
                'value' => $i,
            ];

            $output .= $this->renderChildrenWithScope($loopVars, $children);
        }

        return $output;
    }

    public function storeSection(string $name, string $content, bool $append = false): void
    {
        $this->sections[$name] = $append
            ? ($this->sections[$name] ?? '') . $content
            : $content;
    }

    public function yieldSection(string $name): string
    {
        return $this->sections[$name] ?? '';
    }

    public function storeStack(string $name, string $content, bool $prepend = false): void
    {
        if (! isset($this->stacks[$name])) {
            $this->stacks[$name] = [];
        }

        if ($prepend) {
            array_unshift($this->stacks[$name], $content);

            return;
        }

        $this->stacks[$name][] = $content;
    }

    public function yieldStack(string $name): string
    {
        return implode('', $this->stacks[$name] ?? []);
    }

    /**
     * @param callable(): string $renderer
     */
    public function renderOnce(?string $key, callable $renderer): string
    {
        $resolvedKey = $this->resolveOnceKey($key);
        if (isset($this->onceKeys[$resolvedKey])) {
            return '';
        }

        $this->onceKeys[$resolvedKey] = true;

        return $renderer();
    }

    public function nextIncrement(string $name, int $from = 1, int $step = 1): int
    {
        if (! isset($this->increments[$name])) {
            $this->increments[$name] = $from;

            return $this->increments[$name];
        }

        $this->increments[$name] += $step;

        return $this->increments[$name];
    }

    /**
     * @param array<int, mixed> $values
     */
    public function nextSwitchValue(string $name, array $values): mixed
    {
        $index = $this->switches[$name] ?? 0;

        $this->switches[$name] = $index + 1;

        return $values[$index % count($values)];
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

    /**
     * @param array<string, mixed> $scope
     */
    public function resolvePathValue(string $path, array $scope): mixed
    {
        if ($this->options->guardPolicy->guardsVariable($path)) {
            if ($this->options->strict) {
                throw new AntlersRuntimeException("Guarded variable: \"$path\"");
            }

            return null;
        }

        if ($this->options->strict && ! $this->paths->has($path, $scope)) {
            throw new AntlersRuntimeException("Undefined variable: \"$path\"");
        }

        return $this->paths->get($path, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function pathExists(string $path, array $scope): bool
    {
        return $this->paths->has($path, $scope);
    }

    private function resolveViewPath(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return $name;
        }

        $candidates = [$name];

        $basename = basename($name);
        if (! str_contains($basename, '.')) {
            $candidates[] = $name . '.antlers.html';
        }

        $viewRoots = array_values($this->viewPaths);

        $resolved = $this->firstExistingSafeTemplatePath($viewRoots, $candidates);
        if ($resolved !== null) {
            return $resolved;
        }

        if ($viewRoots !== []) {
            return $this->resolvePathWithinRoot($viewRoots[0], $candidates[0]) ?? '';
        }

        return $this->resolveTemplatePath($candidates[0]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pushScope(array $data): void
    {
        $this->scopeStack[] = $data;
    }

    private function popScope(): void
    {
        array_pop($this->scopeStack);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentScope(): array
    {
        return array_merge($this->globalData, ...$this->scopeStack);
    }

    /**
     * @param array<string, mixed> $scope
     * @param AbstractNode[] $children
     */
    private function renderChildrenWithScope(array $scope, array $children): string
    {
        $this->pushScope($scope);

        try {
            return $this->processNodes($children);
        } finally {
            $this->popScope();
        }
    }

    /**
     * Render children as raw literal text (noparse mode).
     *
     * @param AbstractNode[] $children
     */
    private function childrenAsRaw(array $children): string
    {
        $output = '';
        foreach ($children as $child) {
            if ($child instanceof LiteralNode) {
                $output .= $child->content;
            } elseif ($child instanceof AntlersNode) {
                $output .= '{{' . $child->rawContent . '}}';
            }
        }

        return $output;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeScopeFrame(array $data): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = array_filter(
            $data,
            is_string(...),
            ARRAY_FILTER_USE_KEY,
        );

        return $normalized;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function iterableToArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractItemScope(mixed $item): ?array
    {
        $iterable = $this->iterableToArray($item);
        if ($iterable !== null) {
            return $this->normalizeScopeFrame($iterable);
        }

        if (is_object($item)) {
            return $this->normalizeScopeFrame(get_object_vars($item));
        }

        return null;
    }

    private function toInt(mixed $value): int
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
     * @param array<string, mixed> $scope
     */
    private function stringifyEvaluatedNode(AbstractNode $node, array $scope): string
    {
        return $this->evaluator->stringify($this->evaluateNodeValue($node, $scope));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evaluateNodeValue(AbstractNode $node, array $scope): mixed
    {
        return $this->evaluator->evaluate($node, $scope, $this->assignmentWriter());
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evaluateNodeResult(AbstractNode $node, array $scope): ValueResult
    {
        return $this->evaluator->evaluateResult($node, $scope, $this->assignmentWriter());
    }

    /**
     * @return callable(string, mixed): void
     */
    private function assignmentWriter(): callable
    {
        return $this->writeScopeValue(...);
    }

    private function writeScopeValue(string $name, mixed $value): void
    {
        $this->scopeStack[count($this->scopeStack) - 1][$name] = $value;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolvePathResult(string $path, array $scope): ValueResult
    {
        return new ValueResult($this->resolvePathValue($path, $scope));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $scope
     */
    private function handleTagResult(TagNode $node, array $params, array $scope): ValueResult
    {
        $this->pushTagContext($node);

        try {
            return new ValueResult(
                $this->tags->handle($node->name, $node->method, $params, $scope, $this, $node->children),
            );
        } finally {
            $this->popTagContext();
        }
    }

    /**
     * @param array<string, mixed> $loopVars
     * @return array<string, mixed>
     */
    private function withLoopValue(array $loopVars, string $key, mixed $value): array
    {
        return array_merge($loopVars, [$key => $value]);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{name: string, children: array<AbstractNode>}|null
     */
    private function resolveSlotDefinition(AbstractNode $node, array $scope): ?array
    {
        if ($node instanceof TagNode) {
            $parsed = $node;
        } elseif ($node instanceof AntlersNode && ! $node->isClosingTag) {
            $parsed = $this->parser->parseNode($node);
        } else {
            return null;
        }

        if (! $parsed instanceof TagNode || $parsed->name !== 'slot' || $parsed->children === []) {
            return null;
        }

        $name = $this->slotName($parsed, $scope);

        return [
            'name'     => $name ?? 'default',
            'children' => $parsed->children,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function slotName(TagNode $node, array $scope): ?string
    {
        if ($node->method !== 'index' && $node->method !== '') {
            return $node->method;
        }

        if (! isset($node->parameters['name'])) {
            return null;
        }

        $name = $this->evaluator->stringify($this->evaluateNodeValue($node->parameters['name'], $scope));

        return $name !== '' ? $name : null;
    }

    private function resolveOnceKey(?string $key): string
    {
        if ($key !== null && $key !== '') {
            return 'named:' . $key;
        }

        $context = $this->currentTagContext();
        if ($context === null) {
            return 'anonymous:' . count($this->onceKeys);
        }

        return implode(':', [
            'auto',
            $this->currentTemplateIdentifier(),
            $context['name'],
            $context['method'],
            (string) $context['line'],
            $context['signature'],
        ]);
    }

    private function currentTemplateIdentifier(): string
    {
        if ($this->templatePathStack === []) {
            return '__inline__';
        }

        return $this->templatePathStack[count($this->templatePathStack) - 1];
    }

    private function pushTagContext(TagNode $node): void
    {
        $this->tagContextStack[] = [
            'name'      => $node->name,
            'method'    => $node->method,
            'line'      => $node->line,
            'signature' => hash('sha256', serialize([
                $node->parameters,
                $node->children,
            ])),
        ];
    }

    private function popTagContext(): void
    {
        array_pop($this->tagContextStack);
    }

    /**
     * @return array{name: string, method: string, line: int, signature: string}|null
     */
    private function currentTagContext(): ?array
    {
        $key = array_key_last($this->tagContextStack);

        return $key === null ? null : $this->tagContextStack[$key];
    }

    /**
     * @return non-empty-list<string>
     */
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
                if ($resolved === null) {
                    continue;
                }

                if (is_file($resolved)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $roots
     */
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
        $root = rtrim($root, DIRECTORY_SEPARATOR);
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

        $rootPrefix = $rootReal . DIRECTORY_SEPARATOR;

        return $path === $rootReal || str_starts_with($path, $rootPrefix);
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1);
    }

    private function normalizePath(string $path): ?string
    {
        $path   = str_replace('\\', '/', $path);

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            $prefix = substr($path, 0, 2);
            $path   = substr($path, 2);
        } else {
            $prefix = '';
        }

        $segments = explode('/', ltrim($path, '/'));
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
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
