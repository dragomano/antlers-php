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
    ) {
        parent::__construct(
            $templateLine > 0 ? sprintf('%s on line %d', $reason, $templateLine) : $reason,
            previous: $previous,
        );
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

        return new self($this->reason, $line, $this);
    }
}
