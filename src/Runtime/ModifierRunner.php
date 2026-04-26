<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Modifiers\ModifierRegistry;

/**
 * Applies modifiers from a ModifierRegistry.
 * Separated from ExpressionEvaluator to avoid circular dependency.
 */
final readonly class ModifierRunner
{
    public function __construct(
        private ModifierRegistry $registry,
        private RuntimeOptions $options,
    ) {}

    /**
     * @param list<mixed> $params
     * @param array<string, mixed> $context
     */
    public function apply(string $name, mixed $value, array $params, array $context): mixed
    {
        if ($this->options->guardPolicy->guardsModifier($name)) {
            if ($this->options->strict) {
                throw new AntlersRuntimeException(sprintf('Guarded modifier: "%s"', $name));
            }

            return $value;
        }

        return $this->registry->apply($name, $value, $params, $context);
    }
}
