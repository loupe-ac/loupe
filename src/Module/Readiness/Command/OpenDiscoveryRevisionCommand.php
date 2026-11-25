<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use Symfony\Component\Uid\Uuid;

final readonly class OpenDiscoveryRevisionCommand
{
    public function __construct(
        public Uuid $runId,
        public ?Uuid $previousWorkRequestId,
    ) {
    }
}
