<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Messenger;

/** Reads the billed cost of one run. The run id keys the billed cost row. */
final readonly class ReadBilledCost
{
    public function __construct(
        public string $runId,
    ) {
    }
}
