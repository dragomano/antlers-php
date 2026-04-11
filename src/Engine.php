<?php

declare(strict_types=1);

namespace Bugo\Antlers;

use Bugo\Antlers\Modifiers\CoreModifiers;
use Bugo\Antlers\Modifiers\ModifierInterface;
use Bugo\Antlers\Modifiers\ModifierRegistry;
use Bugo\Antlers\Parser\DocumentParser;
use Bugo\Antlers\Parser\LanguageParser;
use Bugo\Antlers\Runtime\ConditionProcessor;
use Bugo\Antlers\Runtime\ExpressionEvaluator;
use Bugo\Antlers\Runtime\ModifierRunner;
use Bugo\Antlers\Runtime\NodeProcessor;
use Bugo\Antlers\Runtime\PathDataManager;
use Bugo\Antlers\Tags\CoreTags;
use Bugo\Antlers\Tags\TagInterface;
use Bugo\Antlers\Tags\TagRegistry;

/**
 * Main entry point for the Antlers template engine.
 *
 * Usage:
 *   $engine = new Engine();
 *   $html = $engine->render('Hello, {{ name }}!', ['name' => 'World']);
 */
final class Engine
{
    private readonly NodeProcessor $processor;

    /** @var array<string, mixed> */
    private array $globalData = [];

    public function __construct(
        private readonly DocumentParser $documentParser = new DocumentParser(),
        private readonly LanguageParser $languageParser = new LanguageParser(),
        private readonly TagRegistry $tagRegistry = new TagRegistry(),
        private readonly ModifierRegistry $modifierRegistry = new ModifierRegistry(),
    ) {
        // Register built-in modifiers
        CoreModifiers::register($this->modifierRegistry);
        CoreTags::register($this->tagRegistry);

        // Build the runtime pipeline
        $paths      = new PathDataManager();
        $runner     = new ModifierRunner($this->modifierRegistry);
        $evaluator  = new ExpressionEvaluator($paths, $runner);
        $conditions = new ConditionProcessor($evaluator);

        $this->processor = new NodeProcessor(
            $this->documentParser,
            $evaluator,
            $conditions,
            $this->tagRegistry,
            $paths,
            $this->languageParser,
        );
    }

    /**
     * Render a template string with the given data.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $this->processor->setGlobalData($this->globalData);

        $nodes = $this->documentParser->parse($template);

        return $this->processor->reduce($nodes, $data);
    }

    /**
     * Render a template file with the given data.
     *
     * @param array<string, mixed> $data
     */
    public function renderFile(string $path, array $data = []): string
    {
        $this->processor->setGlobalData($this->globalData);

        return $this->processor->renderTemplateFile($path, $data);
    }

    /**
     * Register a custom tag.
     *
     * $engine->addTag('greeting', function($params, $data, $proc, $method, $children) {
     *     return 'Hello, ' . ($params['name'] ?? 'World') . '!';
     * });
     *
     * Usage: {{ greeting name="Alice" }}
     */
    public function addTag(string $name, TagInterface|callable $handler): self
    {
        $this->tagRegistry->register($name, $handler);

        return $this;
    }

    /**
     * Register a custom modifier.
     *
     * $engine->addModifier('money', fn($v, $p) => number_format($v, 2) . ' ' . ($p[0] ?? 'USD'));
     *
     * Usage: {{ price | money:EUR }}
     */
    public function addModifier(string $name, ModifierInterface|callable $handler): self
    {
        $this->modifierRegistry->register($name, $handler);

        return $this;
    }

    /**
     * Add a single global variable available in all templates.
     */
    public function addGlobal(string $key, mixed $value): self
    {
        $this->globalData[$key] = $value;

        return $this;
    }

    /**
     * Merge an array of global variables.
     *
     * @param array<string, mixed> $data
     */
    public function setGlobals(array $data): self
    {
        $this->globalData = array_merge($this->globalData, $data);

        return $this;
    }

    /**
     * In strict mode, accessing an undefined variable throws an AntlersRuntimeException.
     * By default, it returns an empty string (lenient mode).
     */
    public function setStrictMode(bool $strict): self
    {
        $this->processor->setStrict($strict);

        return $this;
    }
}
