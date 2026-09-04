<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\GuardPolicy;
use Bugo\Antlers\Support\CommonMarkRenderer;
use Bugo\Antlers\Support\MarkdownRendererInterface;

final class RuntimeOptions
{
    public bool $strict = false;

    public bool $debug = false;

    public GuardPolicy $guardPolicy;

    public MarkdownRendererInterface $markdownRenderer;

    public function __construct()
    {
        $this->guardPolicy      = new GuardPolicy();
        $this->markdownRenderer = new CommonMarkRenderer();
    }
}
