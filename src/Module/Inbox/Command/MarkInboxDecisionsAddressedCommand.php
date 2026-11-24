<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class MarkInboxDecisionsAddressedCommand
{
    /** @param list<Uuid> $decisionIds each id once */
    public function __construct(
        public Project $project,
        public array $decisionIds,
        public Uuid $sessionId,
        /** The run of the run credential of the call, when it holds one. Otherwise the handler takes the newest run of the session. */
        public ?WorkerRun $credentialRun = null,
    ) {
    }
}
