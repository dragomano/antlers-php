<?php

declare(strict_types=1);

namespace Bugo\Antlers\Exceptions;

use RuntimeException;
use Throwable;

final class AntlersRuntimeException extends RuntimeException
{
    public function __construct(
        private readonly string $reason,
        public readonly int $templateLine = 0,
        ?Throwable $previous = null,
        public readonly ?string $templateName = null,
    ) {
        parent::__construct($this->composeMessage(), previous: $previous);
    }

    /**
     * Attributes the failure to a template line. Throw sites deep in evaluation
     * do not know where they are, so the line is attached as the exception
     * passes the statement being rendered; the innermost known line wins.
     */
    public function atLine(int $line): self
    {
        if ($this->templateLine !== 0) {
            return $this;
        }

        return new self($this->reason, $line, $this, $this->templateName);
    }

    /**
     * Attributes the failure to a template file. The name is attached as the
     * exception crosses a rendered file's boundary; the innermost file (a
     * partial over the view that includes it) wins, mirroring atLine().
     */
    public function atTemplate(string $name): self
    {
        if ($this->templateName !== null) {
            return $this;
        }

        return new self($this->reason, $this->templateLine, $this, $name);
    }

    private function composeMessage(): string
    {
        $message = $this->reason;

        if ($this->templateName !== null) {
            $message .= ' in ' . $this->templateName;
        }

        if ($this->templateLine > 0) {
            $message .= ' on line ' . $this->templateLine;
        }

        return $message;
    }
}
