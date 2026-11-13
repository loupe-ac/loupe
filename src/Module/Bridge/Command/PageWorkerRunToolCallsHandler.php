<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunToolCallRepository;

/** Reads one keyset page of the tool calls of a run, in sequence order. */
final readonly class PageWorkerRunToolCallsHandler
{
    public function __construct(
        private WorkerRunToolCallRepository $workerRunToolCalls,
    ) {
    }

    public function __invoke(PageWorkerRunToolCallsCommand $command): PageWorkerRunToolCallsView
    {
        $calls = $this->workerRunToolCalls->findAfterSeqOfRun($command->run, $command->afterSeq, $command->limit + 1);

        return new PageWorkerRunToolCallsView(\array_slice($calls, 0, $command->limit), \count($calls) > $command->limit);
    }
}
