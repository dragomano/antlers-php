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
use Bugo\Antlers\Support\MarkdownRendererInterface;
use Bugo\Antlers\Tags\TagRegistry;

/**
 * Stage 4: Walks the parsed AST, evaluates nodes, and produces the final string output.
 */
final class NodeProcessor
{
    private readonly TemplateLocator $templateLocator;

    private readonly TemplateRepository $templateRepository;

    private readonly LoopRenderer $loopRenderer;

    private readonly SlotRenderer $slotRenderer;

    private readonly TagInvoker $tagInvoker;

    /** @var array<string, mixed> */
    private array $globalData = [];

    private ?RenderContext $context = null;

    private RenderContext $standaloneContext;

    public function __construct(
        DocumentParser $documentParser,
        private readonly ExpressionEvaluator $evaluator,
        private readonly ConditionProcessor $conditions,
        private readonly TagRegistry $tags,
        private readonly PathDataManager $paths,
        private readonly LanguageParser $parser,
        private readonly RuntimeOptions $options,
    ) {
        $this->templateLocator    = new TemplateLocator();
        $this->templateRepository = new TemplateRepository($documentParser, $this->templateLocator);
        $this->loopRenderer       = new LoopRenderer(
            fn(array $scope, array $children): string
                => $this->renderChildrenWithScope($scope, $children, $this->context()),
        );

        $this->slotRenderer = new SlotRenderer(
            $this->parser,
            $this->evaluator,
            fn(array $children, array $data): string => $this->renderFragment($children, $data),
            fn(AbstractNode $node, array $scope): mixed
                => $this->evaluateNodeValue($node, $scope, $this->context()),
        );

        $this->tagInvoker = new TagInvoker(
            $this->tags,
            $this->options,
            fn(AbstractNode $node, array $scope): mixed
                => $this->evaluateNodeValue($node, $scope, $this->context()),
            fn(string $name, string $method, array $parameters, array $scope, array $children): mixed
                => $this->tags->handle($name, $method, $parameters, $scope, $this, $children),
        );

        $this->standaloneContext = new RenderContext($this->globalData);

        $this->evaluator->setProcessor($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setGlobalData(array $data): void
    {
        $this->globalData = $data;
    }

    /** @param string|string[] $paths */
    public function setViewPaths(string|array $paths): void
    {
        $this->templateLocator->setViewPaths($paths);
    }

    public function markdownRenderer(): MarkdownRendererInterface
    {
        return $this->options->markdownRenderer();
    }

    public function isDebugEnabled(): bool
    {
        return $this->options->debug;
    }

    /**
     * Reports a runtime failure according to the lenient/strict policy.
     * Tags use this instead of deciding for themselves.
     *
     * @template T
     * @param  T $fallback
     * @return T
     */
    public function fail(string $reason, mixed $fallback = ''): mixed
    {
        return $this->options->fail($reason, $fallback);
    }

    /**
     * @param AbstractNode[] $nodes
     * @param array<string, mixed> $data
     */
    public function reduce(array $nodes, array $data = []): string
    {
        $isRoot  = ! $this->context instanceof RenderContext;
        $context = $this->context ??= new RenderContext($this->globalData);

        try {
            return $context->renderFrame(
                $data,
                fn(): string => $this->processNodes($nodes, $context),
            );
        } finally {
            if ($isRoot) {
                $this->standaloneContext = $this->context;
                $this->context = null;
            }
        }
    }

    /**
     * @param AbstractNode[] $nodes
     */
    private function processNodes(array $nodes, RenderContext $context): string
    {
        $output = '';

        foreach ($nodes as $node) {
            try {
                $output .= $this->processNode($node, $context->scope->all(), $context);
            } catch (AntlersRuntimeException $e) {
                throw $e->atLine($node->line);
            }
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processNode(AbstractNode $node, array $scope, RenderContext $context): string
    {
        // Literal text — pass through unchanged
        if ($node instanceof LiteralNode) {
            return $node->content;
        }

        // Parsed typed nodes from LanguageParser
        if ($node instanceof ConditionNode) {
            return $this->processCondition($node, $scope, $context);
        }

        if ($node instanceof LoopNode) {
            return $this->processLoop($node, $scope, $context);
        }

        if ($node instanceof SetNode) {
            $context->scope->write($node->variableName, $this->evaluateNodeValue($node->value, $scope, $context));

            return '';
        }

        if ($node instanceof AssignmentNode) {
            $result = $this->evaluateNodeResult($node->value, $scope, $context);

            $context->scope->write($node->variableName, $result->value);

            if ($node->children === []) {
                return '';
            }

            return $this->processPairedValue($result->value, $node->children, $context);
        }

        if ($node instanceof SequenceNode) {
            $result = $this->evaluateNodeResult($node, $scope, $context);

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
            return $this->stringifyEvaluatedNode($node, $scope, $context);
        }

        // Raw AntlersNode — needs parsing first
        if ($node instanceof AntlersNode) {
            return $this->processRawAntlersNode($node, $scope, $context);
        }

        // All other expression nodes
        return $this->stringifyEvaluatedNode($node, $scope, $context);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processRawAntlersNode(AntlersNode $node, array $scope, RenderContext $context): string
    {
        if ($node->isClosingTag) {
            return '';
        }

        $recursive = $this->recursiveDirective($node->rawContent);
        if ($recursive !== null) {
            return $this->processRecursive($recursive['path'], $recursive['maxDepth'], $context);
        }

        // Noparse block — render children as raw text
        if ($node->name === 'noparse') {
            return $this->childrenAsRaw($node->children);
        }

        // Paired variable block: {{ items }}...{{ /items }}
        // DocumentParser filled $node->children; language constructs (if/foreach/for) parse themselves below.
        if ($node->children !== [] && ! in_array($node->name, DocumentParser::BUILTIN_BLOCKS, strict: true)) {
            $parsed = $this->parser->parseNode($node);

            // Could be a paired tag in the registry
            if ($parsed instanceof TagNode && $this->tags->has($parsed->name)) {
                return $this->processNode($parsed, $scope, $context);
            }

            if ($parsed instanceof AssignmentNode) {
                return $this->processNode($parsed, $scope, $context);
            }

            if ($parsed instanceof VariableNode) {
                return $this->processPairedValue(
                    $this->resolvePathResult($parsed->path, $scope)->value,
                    $node->children,
                    $context,
                );
            }

            // Otherwise: paired variable loop
            return $this->processPairedValue(
                $this->resolvePathResult($node->rawContent, $scope)->value,
                $node->children,
                $context,
            );
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

        return $this->processNode($parsed, $scope, $context);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processCondition(ConditionNode $node, array $scope, RenderContext $context): string
    {
        $children = $this->conditions->process($node, $scope, $this->assignmentWriter($context));
        if ($children === []) {
            return '';
        }

        // A condition is not a scope: it adds no variables, and opening a frame
        // here would throw away assignments made inside the branch.
        return $this->processNodes($children, $context);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processLoop(LoopNode $node, array $scope, RenderContext $context): string
    {
        if ($node->type === 'foreach') {
            return $this->processForeach($node, $scope, $context);
        }

        if ($node->type === 'for') {
            return $this->processFor($node, $scope, $context);
        }

        if ($node->type === 'paired') {
            return $this->loopRenderer->renderItems(
                $this->resolvePathResult($node->variablePath ?? '', $scope)->value,
                $node->children,
            );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processForeach(LoopNode $node, array $scope, RenderContext $context): string
    {
        if (! $node->iterable instanceof AbstractNode) {
            return '';
        }

        $items = $this->evaluateNodeValue($node->iterable, $scope, $context);

        if (! is_iterable($items)) {
            return '';
        }

        return $this->loopRenderer->renderItems($items, $node->children, $node->alias, $node->keyAlias);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processFor(LoopNode $node, array $scope, RenderContext $context): string
    {
        if (! $node->from instanceof AbstractNode || ! $node->to instanceof AbstractNode) {
            return '';
        }

        $from = ValueCoercion::toInt($this->evaluateNodeValue($node->from, $scope, $context));
        $to   = ValueCoercion::toInt($this->evaluateNodeValue($node->to, $scope, $context));

        return $this->loopRenderer->renderCounter($from, $to, $node->children);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function processTag(TagNode $node, array $scope): string
    {
        return $this->evaluator->stringify($this->callTag($node, $scope));
    }

    /** @param array<string, mixed> $scope */
    public function callTag(TagNode $node, array $scope): mixed
    {
        return $this->tagInvoker->call($node, $scope);
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

        return $this->processPairedValue($value->value, $children, $this->context());
    }

    /**
     * @param AbstractNode[] $children
     */
    private function processPairedValue(mixed $value, array $children, RenderContext $context): string
    {
        return $context->recursion->within(
            $children,
            fn(): string => $this->renderPairedValue($value, $children, $context),
        );
    }

    /** @param AbstractNode[] $children */
    private function renderPairedValue(mixed $value, array $children, RenderContext $context): string
    {
        $items = ValueCoercion::toArray($value);
        if ($items !== null && $items !== []) {
            // Gaps left by array_filter() or unset() and 1-based data are still a
            // collection; only string keys mean "one item, use its fields".
            if (array_filter(array_keys($items), is_string(...)) === []) {
                return $this->loopRenderer->renderItems($items, $children);
            }

            // Single associative item — render with merged scope
            return $this->renderChildrenWithScope(ValueCoercion::stringKeys($items), $children, $context);
        }

        $itemScope = ValueCoercion::toScopeFrame($value);
        if ($itemScope !== null && $itemScope !== []) {
            return $this->renderChildrenWithScope($itemScope, $children, $context);
        }

        if ($this->evaluator->isTruthy($value)) {
            // Scalar or object with nothing to add to the scope: render in place,
            // so the body behaves like a condition and keeps its assignments.
            return $this->processNodes($children, $context);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderTemplate(string $template, array $data = []): string
    {
        $nodes = $this->templateRepository->parse($template);

        return $this->reduce($nodes, $data);
    }

    /** @param array<string, mixed> $data */
    public function renderTemplateFile(string $path, array $data = []): string
    {
        return $this->templateRepository->renderFile(
            $path,
            $data,
            fn(array $nodes, array $scope): string => $this->reduce($nodes, $scope),
        );
    }

    /** @param array<string, mixed> $data */
    public function renderView(string $name, array $data = []): string
    {
        return $this->templateRepository->renderView(
            $name,
            $data,
            fn(array $nodes, array $scope): string => $this->reduce($nodes, $scope),
        );
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
        return $this->slotRenderer->render($children, $data);
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
        return $this->loopRenderer->renderItems($items, $children, $alias, $keyAlias);
    }

    /** @param AbstractNode[] $children */
    public function renderCounterLoop(int $from, int $to, array $children): string
    {
        return $this->loopRenderer->renderCounter($from, $to, $children);
    }

    public function storeSection(string $name, string $content, bool $append = false): void
    {
        $this->context()->state->storeSection($name, $content, $append);
    }

    public function yieldSection(string $name): string
    {
        return $this->context()->state->section($name);
    }

    public function storeStack(string $name, string $content, bool $prepend = false): void
    {
        $this->context()->state->pushStack($name, $content, $prepend);
    }

    public function yieldStack(string $name): string
    {
        return $this->context()->state->stack($name);
    }

    /**
     * @param callable(): string $renderer
     */
    public function renderOnce(?string $key, callable $renderer): string
    {
        return $this->context()->state->markOnce($this->resolveOnceKey($key)) ? $renderer() : '';
    }

    public function nextIncrement(string $name, int $from = 1, int $step = 1): int
    {
        return $this->context()->state->nextIncrement($name, $from, $step);
    }

    /**
     * @param array<int, mixed> $values
     */
    public function nextSwitchValue(string $name, array $values): mixed
    {
        return $values[$this->context()->state->nextSwitchIndex($name) % count($values)];
    }

    public function resolveTemplatePath(string $path): string
    {
        return $this->templateLocator->resolveTemplatePath($path);
    }

    public function resolveTemplateTagPath(string $path): string
    {
        return $this->templateLocator->resolveTemplateTagPath($path);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function resolvePathValue(string $path, array $scope): mixed
    {
        return $this->evaluator->resolveVariable($path, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function pathExists(string $path, array $scope): bool
    {
        return $this->paths->has($path, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @param AbstractNode[] $children
     */
    private function renderChildrenWithScope(array $scope, array $children, RenderContext $context): string
    {
        return $context->renderFrame(
            $scope,
            fn(): string => $this->processNodes($children, $context),
        );
    }

    /**
     * Render children as raw literal text (noparse mode).
     *
     * @param AbstractNode[] $children
     */
    /** @return array{path: string, maxDepth: int}|null */
    private function recursiveDirective(string $raw): ?array
    {
        if (preg_match('/^\*recursive\s+([^\s*]+)(.*?)\*(.*)$/s', trim($raw), $matches) !== 1) {
            return null;
        }

        $options  = trim($matches[2] . ' ' . $matches[3]);
        $maxDepth = PHP_INT_MAX;
        if (preg_match('/max_depth\s*=\s*["\']?(\d+)/', $options, $depth) === 1) {
            $maxDepth = max(0, (int) $depth[1]);
        }

        return ['path' => $matches[1], 'maxDepth' => $maxDepth];
    }

    private function processRecursive(string $path, int $maxDepth, RenderContext $context): string
    {
        $frame = $context->scope->current();
        $root  = substr($path, 0, strcspn($path, '.:['));

        return $context->recursion->descend(
            $maxDepth,
            fn(array $children): string => $context->renderFrame(
                [$root => null],
                fn(): string => $this->renderPairedValue(
                    $this->resolvePathResult($path, $frame)->value,
                    $children,
                    $context,
                ),
            ),
        );
    }

    /** @param AbstractNode[] $children */
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
     * @param array<string, mixed> $scope
     */
    private function stringifyEvaluatedNode(
        AbstractNode $node,
        array $scope,
        RenderContext $context,
    ): string {
        return $this->evaluator->stringify($this->evaluateNodeValue($node, $scope, $context));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evaluateNodeValue(
        AbstractNode $node,
        array $scope,
        RenderContext $context,
    ): mixed {
        return $this->evaluator->evaluate($node, $scope, $this->assignmentWriter($context));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evaluateNodeResult(
        AbstractNode $node,
        array $scope,
        RenderContext $context,
    ): ValueResult {
        return $this->evaluator->evaluateResult($node, $scope, $this->assignmentWriter($context));
    }

    /**
     * @return callable(string, mixed): void
     */
    private function assignmentWriter(RenderContext $context): callable
    {
        return $context->scope->write(...);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolvePathResult(string $path, array $scope): ValueResult
    {
        return new ValueResult($this->resolvePathValue($path, $scope));
    }

    private function resolveOnceKey(?string $key): string
    {
        if ($key !== null && $key !== '') {
            return 'named:' . $key;
        }

        $context = $this->tagInvoker->currentContext();
        if ($context === null) {
            return 'anonymous:' . $this->context()->state->onceCount();
        }

        return implode(':', [
            'auto',
            $this->currentTemplateIdentifier(),
            $context['name'],
            $context['method'],
            (string) $context['line'],
            $this->tagInvoker->currentSignature() ?? '',
        ]);
    }

    private function currentTemplateIdentifier(): string
    {
        return $this->templateLocator->currentTemplateIdentifier();
    }


    private function context(): RenderContext
    {
        return $this->context ?? $this->standaloneContext;
    }
}
