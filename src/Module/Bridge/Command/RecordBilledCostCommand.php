<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use Symfony\Component\Uid\Uuid;

/**
 * The next state of the billed cost of one run. A pending state carries a read
 * that settled nothing, with the total that the next read must confirm.
 */
final readonly class RecordBilledCostCommand
{
    public function __construct(
        public Uuid $runId,
        public BilledCostState $state,
        public ?BilledCostReason $reason = null,
        public ?string $costUsd = null,
        public ?string $firstReadCost = null,
        public int $attempts = 0,
    ) {
    }
}
