<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Unreadable;

/**
 * A reference to a named expression of the template. It answers as the expression does, so subject binding,
 * the leaves and the rule fingerprint see the expression alone. Only the settings page reads the name.
 */
final readonly class NamedFact extends Expression
{
    public function __construct(
        public string $name,
        public Expression $expression,
    ) {
    }

    #[\Override]
    public function evaluate(Facts $facts): bool
    {
        return $this->expression->evaluate($facts);
    }

    #[\Override]
    public function unreadable(Facts $facts): ?Unreadable
    {
        return $this->expression->unreadable($facts);
    }

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        return $this->expression->leafAgainst($facts, $wanted);
    }

    #[\Override]
    public function countAgainst(Facts $facts, bool $wanted): int
    {
        return $this->expression->countAgainst($facts, $wanted);
    }

    #[\Override]
    public function reads(): array
    {
        return $this->expression->reads();
    }

    #[\Override]
    public function leaves(): array
    {
        return $this->expression->leaves();
    }

    #[\Override]
    public function requiredLeaves(): array
    {
        return $this->expression->requiredLeaves();
    }

    #[\Override]
    public function holdingParts(Facts $facts): array
    {
        return $this->expression->holdingParts($facts);
    }

    #[\Override]
    public function canonical(): string
    {
        return $this->expression->canonical();
    }

    #[\Override]
    public function missingKeys(): array
    {
        return $this->expression->missingKeys();
    }
}
