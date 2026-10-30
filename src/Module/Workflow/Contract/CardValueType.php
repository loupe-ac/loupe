<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The type of a value a card can hold. */
enum CardValueType: string
{
    case Bool = 'bool';

    /** Whether a stored or submitted value is of this type. */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::Bool => \is_bool($value),
        };
    }
}
