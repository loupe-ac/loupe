<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** An offered request that a bridge skipped because the account it matched is blocked. */
final readonly class SkippedWorkRequest
{
    public function __construct(
        public Uuid $workRequestId,
        public string $account,
    ) {
    }
}
