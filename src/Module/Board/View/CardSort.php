<?php

declare(strict_types=1);

namespace App\Module\Board\View;

/**
 * A walk order of the public card list, as the `sort` query writes it. A card
 * number counts up in creation order, so the creation sorts read the number.
 */
final readonly class CardSort
{
    /** @var list<string> */
    public const array VALUES = ['number', '-number', 'createdAt', '-createdAt', 'updatedAt', '-updatedAt'];

    private function __construct(
        public string $value,
    ) {
    }

    public static function number(): self
    {
        return new self('number');
    }

    public static function tryFrom(string $value): ?self
    {
        return \in_array($value, self::VALUES, true) ? new self($value) : null;
    }

    /** @return 'number'|'updatedAt' */
    public function field(): string
    {
        return str_ends_with($this->value, 'updatedAt') ? 'updatedAt' : 'number';
    }

    public function descending(): bool
    {
        return str_starts_with($this->value, '-');
    }
}
