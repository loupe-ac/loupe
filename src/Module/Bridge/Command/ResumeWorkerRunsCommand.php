<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Security\ApiKeyActor;

final readonly class ResumeWorkerRunsCommand
{
    /** @param non-empty-list<ResumeWorkerRunsEntry> $entries from 1 to 50 runs, in the order to try them */
    public function __construct(
        public User $requestedBy,
        public BridgeCommandCause $cause,
        public array $entries,
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
