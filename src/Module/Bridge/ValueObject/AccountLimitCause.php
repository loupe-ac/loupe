<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Why an account takes no new work. */
enum AccountLimitCause: string
{
    case Limit = 'limit';
    case Stop = 'stop';
}
