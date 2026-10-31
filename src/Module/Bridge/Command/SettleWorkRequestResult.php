<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;

/**
 * The settled request, or the reason the bridge cannot settle it. $settled is
 * false when the same result came again and changed nothing. $handedBack is
 * true when the bridge gave the request back and it opened again.
 */
final readonly class SettleWorkRequestResult
{
    public function __construct(
        public ?WorkRequest $request,
        public bool $settled = false,
        public ?WorkRequestRefusal $refusal = null,
        public bool $handedBack = false,
    ) {
    }
}
