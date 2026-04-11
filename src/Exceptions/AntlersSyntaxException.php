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
        parent::__construct($message);
    }
}
