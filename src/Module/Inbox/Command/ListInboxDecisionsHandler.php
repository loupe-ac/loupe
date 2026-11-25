<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Repository\InboxDecisionRepository;

/** Reads the changes of the owner on one card that no worker acted on yet, oldest first. */
final readonly class ListInboxDecisionsHandler
{
    public function __construct(
        private InboxDecisionRepository $inboxDecisions,
    ) {
    }

    /** @return list<InboxDecision> */
    public function __invoke(ListInboxDecisionsCommand $command): array
    {
        return $this->inboxDecisions->findOpenChangesOfCard($command->project, $command->cardId);
    }
}
