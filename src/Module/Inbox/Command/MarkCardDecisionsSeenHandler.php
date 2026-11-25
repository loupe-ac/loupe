<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxDecisionSeenRepository;
use App\Module\Inbox\Service\InboxChangedPublisher;

/** Records that the viewer opened the Decisions tab of a card, so the digest of the inbox counts from the newest decision the tab showed. */
final readonly class MarkCardDecisionsSeenHandler
{
    public function __construct(
        private InboxDecisionSeenRepository $inboxDecisionSeens,
        private InboxChangedPublisher $inboxChanged,
    ) {
    }

    public function __invoke(MarkCardDecisionsSeenCommand $command): void
    {
        $this->inboxDecisionSeens->markSeen($command->card->project, $command->viewer, $command->card, $command->through);
        $this->inboxChanged->pageChanged($command->card->project);
    }
}
