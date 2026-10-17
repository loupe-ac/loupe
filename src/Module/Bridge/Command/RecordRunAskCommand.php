<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use Symfony\Component\Uid\Uuid;

final readonly class RecordRunAskCommand
{
    public function __construct(
        public WorkerRun $run,
        public Uuid $askId,
    ) {
    }
}
