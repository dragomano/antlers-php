<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

interface TagInterface
{
    public function handle(TagContext $context): mixed;
}
