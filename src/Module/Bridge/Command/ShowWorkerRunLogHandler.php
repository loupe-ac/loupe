<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;

final readonly class ShowWorkerRunLogHandler
{
    public function __construct(
        private WorkerRunOutputChunkRepository $workerRunOutputChunks,
    ) {
    }

    public function __invoke(ShowWorkerRunLogCommand $command): ShowWorkerRunLogView
    {
        return new ShowWorkerRunLogView(
            $this->workerRunOutputChunks->fullText($command->run),
            $this->workerRunOutputChunks->isCut($command->run),
        );
    }
}
