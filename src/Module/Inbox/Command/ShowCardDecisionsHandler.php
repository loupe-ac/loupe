<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxDecisionRepository;

/** Reads the decision log of one card for its Decisions tab. */
final readonly class ShowCardDecisionsHandler
{
    public function __construct(
        private InboxDecisionRepository $inboxDecisions,
    ) {
    }

    public function __invoke(ShowCardDecisionsCommand $command): ShowCardDecisionsView
    {
        return new ShowCardDecisionsView($command->card, $this->inboxDecisions->findOfCard($command->card));
    }
}
