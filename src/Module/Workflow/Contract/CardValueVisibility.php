<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Who may write a value, and where the card page shows it. */
enum CardValueVisibility: string
{
    /** Any caller may write the value, and the card page shows it as a control. */
    case Public = 'public';

    /** Only the workflow may write the value, and the card page shows it read-only in a closed section. */
    case Internal = 'internal';
}
