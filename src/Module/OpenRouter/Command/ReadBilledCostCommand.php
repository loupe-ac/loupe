<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use Symfony\Component\Uid\Uuid;

final readonly class ReadBilledCostCommand
{
    public function __construct(
        public Uuid $runId,
    ) {
    }
}
