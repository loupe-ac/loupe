<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;

final readonly class WorkerRunOutputExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunOutputChunkRepository $workerRunOutputChunks,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'worker_run_output.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->workerRunOutputChunks->joinedByOwner($user) as $row) {
            yield [
                'project' => $row['project'],
                'runKey' => $row['run_key'],
                'length' => (int) $row['length'],
                'capped' => $row['capped'],
                'text' => $row['text'],
            ];
        }
    }
}
