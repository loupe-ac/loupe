<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;
use App\Module\Bridge\ValueObject\WorkerRunOutputLog;

final readonly class ShowWorkerRunOutputHandler
{
    public function __construct(
        private WorkerRunOutputChunkRepository $workerRunOutputChunks,
    ) {
    }

    public function __invoke(ShowWorkerRunOutputCommand $command): ShowWorkerRunOutputView
    {
        $slice = $this->workerRunOutputChunks->textFrom($command->run, max(0, $command->from), WorkerRunOutputLog::MAX_READ_BYTES);

        return new ShowWorkerRunOutputView($slice->text, $slice->length, $this->workerRunOutputChunks->isCut($command->run));
    }
}
