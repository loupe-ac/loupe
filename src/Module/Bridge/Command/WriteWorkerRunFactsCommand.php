<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use Symfony\Component\Uid\Uuid;

final readonly class WriteWorkerRunFactsCommand
{
    /** @param list<Uuid> $runIds */
    public function __construct(
        public Uuid $projectId,
        public array $runIds,
    ) {
    }
}
