<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use Symfony\Component\Uid\Uuid;

/** The fact rows of these runs were written by the background job. Dispatched after the commit, with ids only. */
final readonly class WorkerRunFactsWritten
{
    /** @param list<string> $runIds RFC 4122 strings */
    public function __construct(
        public Uuid $projectId,
        public array $runIds,
    ) {
    }
}
