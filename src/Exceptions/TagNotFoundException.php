<?php

declare(strict_types=1);

namespace Bugo\Antlers\Exceptions;

final class TagNotFoundException extends AntlersRuntimeException
{
    public function __construct(string $tagName)
    {
        parent::__construct("Tag not found: [$tagName]");
    }
}
