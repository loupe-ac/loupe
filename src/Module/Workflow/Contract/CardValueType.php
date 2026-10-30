<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The type of a value a card can hold. */
enum CardValueType: string
{
    case Bool = 'bool';
}
