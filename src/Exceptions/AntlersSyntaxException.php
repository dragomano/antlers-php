<?php

declare(strict_types=1);

namespace Bugo\Antlers\Exceptions;

use RuntimeException;

final class AntlersSyntaxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $templateLine = 0,
        public readonly string $templateSource = '',
    ) {
        parent::__construct($this->describe($message));
    }

    /**
     * Template authors get the position and the offending snippet, not just the
     * reason, so getMessage() alone is enough to locate the problem.
     */
    private function describe(string $message): string
    {
        if ($this->templateLine > 0) {
            $message .= sprintf(' on line %d', $this->templateLine);
        }

        $source = trim((string) preg_replace('/\s+/', ' ', $this->templateSource));

        return $source === '' ? $message : $message . sprintf(' in "%s"', $source);
    }
}
