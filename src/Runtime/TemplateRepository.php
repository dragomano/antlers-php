<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Parser\DocumentParser;

final class TemplateRepository
{
    /** @var string[] */
    private array $renderStack = [];

    public function __construct(
        private readonly DocumentParser $parser,
        private readonly TemplateLocator $locator,
    ) {}

    /** @return AbstractNode[] */
    public function parse(string $template): array
    {
        return $this->parser->parse($template);
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(AbstractNode[], array<string, mixed>): string $renderer
     */
    public function renderFile(string $path, array $data, callable $renderer): string
    {
        $resolved = $this->locator->resolveTemplatePath($path);
        if ($resolved === '') {
            throw new AntlersRuntimeException('Template file is outside the configured template roots: ' . $path);
        }

        if (! is_file($resolved)) {
            throw new AntlersRuntimeException('Template file not found: ' . $resolved);
        }

        if (in_array($resolved, $this->renderStack, true)) {
            throw new AntlersRuntimeException('Recursive template rendering detected: ' . $resolved);
        }

        $this->renderStack[] = $resolved;
        $this->locator->pushTemplate($resolved);

        try {
            $template = (string) file_get_contents($resolved);

            return $renderer($this->parse($template), $data);
        } finally {
            $this->locator->popTemplate();
            array_pop($this->renderStack);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(AbstractNode[], array<string, mixed>): string $renderer
     */
    public function renderView(string $name, array $data, callable $renderer): string
    {
        $resolved = $this->locator->resolveViewPath($name);
        if (! is_file($resolved)) {
            throw new AntlersRuntimeException('Template view not found: ' . $name);
        }

        return $this->renderFile($resolved, $data, $renderer);
    }
}
