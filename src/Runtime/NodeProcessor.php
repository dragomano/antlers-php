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

    private bool $strict = false;

    /** @var array<int, array<string, mixed>> Scope stack; each entry is a data frame */
    private array $scopeStack = [];

    /** @var array<string, string> */
    private array $sections = [];

    /** @var array<string, int> */
    private array $increments = [];

    /** @var array<string, int> */
    private array $switches = [];

    /** @var string[] */
    private array $templatePathStack = [];

    public function __construct(
        private readonly DocumentParser $documentParser,
        private readonly ExpressionEvaluator $evaluator,
        private readonly ConditionProcessor $conditions,
        private readonly TagRegistry $tags,
        private readonly PathDataManager $paths,
        private readonly LanguageParser $parser,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function setGlobalData(array $data): void
    {
        $this->globalData = $data;
    }

    public function setStrict(bool $strict): void
    {
        $this->strict = $strict;

        $this->evaluator->setStrict($strict);
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
            return $this->evaluator->stringify($this->paths->get($raw, $scope));
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

        $this->pushScope([]);

        $output = $this->processNodes($children);

        $this->popScope();

        return $output;
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
            return $this->iterateItems($this->resolvePathResult($node->variablePath ?? '', $scope)->value, $node->children);
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

            $this->pushScope($loopVars);

            $output .= $this->processNodes($node->children);

            $this->popScope();
        }

        return $output;
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

        $itemArray  = is_array($items) ? $items : iterator_to_array($items);
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

            // Merge item data into scope
            if (is_array($item)) {
                $loopVars = array_merge($loopVars, $this->normalizeScopeFrame($item));
            } elseif (is_object($item)) {
                $loopVars = array_merge($loopVars, $this->normalizeScopeFrame((array) $item));
            } else {
                $loopVars = $this->withLoopValue($loopVars, 'value', $item);
            }

            // Named alias
            if ($alias !== null) {
                $loopVars = $this->withLoopValue($loopVars, $alias, $item);
            }

            if ($keyAlias !== null) {
                $loopVars[$keyAlias] = $key;
            }

            $this->pushScope($loopVars);

            $output .= $this->processNodes($children);

            $this->popScope();
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

        if (is_array($item)) {
            return $item;
        }

        if (is_object($item)) {
            return (array) $item;
        }

        return ['value' => $item];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processTag(TagNode $node, array $scope): string
    {
        if (! $this->tags->has($node->name)) {
            if ($this->strict) {
                throw new AntlersRuntimeException("Unknown tag: \"$node->name\"");
            }

            return '';
        }

        // Resolve parameter values
        $params = array_map(fn(AbstractNode $paramNode): mixed => $this->evaluateNodeValue($paramNode, $scope), $node->parameters);

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
        if ($value instanceof Traversable) {
            return $this->iterateItems($value, $children);
        }

        if (is_array($value) && $value !== []) {
            // Check if it's a list (numeric keys) or associative array
            if (array_is_list($value)) {
                return $this->iterateItems($value, $children);
            }

            // Single associative item — render with merged scope
            $this->pushScope($this->normalizeScopeFrame($value));

            $output = $this->processNodes($children);

            $this->popScope();

            return $output;
        }

        if ($this->evaluator->isTruthy($value)) {
            // Scalar or object — just render children with current scope
            $this->pushScope([]);

            $output = $this->processNodes($children);

            $this->popScope();

            return $output;
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
        if (! is_file($resolved)) {
            throw new AntlersRuntimeException("Template file not found: $resolved");
        }

        $this->templatePathStack[] = dirname($resolved);

        try {
            return $this->renderTemplate((string) file_get_contents($resolved), $data);
        } finally {
            array_pop($this->templatePathStack);
        }
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

            $this->pushScope($loopVars);
            $output .= $this->processNodes($children);
            $this->popScope();
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

        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        $base = $this->templatePathStack !== []
            ? $this->templatePathStack[count($this->templatePathStack) - 1]
            : getcwd();

        return rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function resolvePathValue(string $path, array $scope): mixed
    {
        if ($this->strict && ! $this->paths->has($path, $scope)) {
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
        if ($this->scopeStack === []) {
            return $this->globalData;
        }

        return array_merge($this->globalData, ...$this->scopeStack);
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
        return new ValueResult($this->paths->get($path, $scope));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $scope
     */
    private function handleTagResult(TagNode $node, array $params, array $scope): ValueResult
    {
        return new ValueResult(
            $this->tags->handle($node->name, $node->method, $params, $scope, $this, $node->children),
        );
    }

    /**
     * @param array<string, mixed> $loopVars
     * @return array<string, mixed>
     */
    private function withLoopValue(array $loopVars, string $key, mixed $value): array
    {
        return array_merge($loopVars, [$key => $value]);
    }
}
