<?php

declare(strict_types=1);

namespace App\Api\Idempotency;

enum IdempotencyState: string
{
    case Running = 'running';
    case Finished = 'finished';
}
