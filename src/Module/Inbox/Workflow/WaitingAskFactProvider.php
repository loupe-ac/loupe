<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

/** An inbox that is off holds no ask, so a rule that waits on its absence still reads the card. */
final readonly class WaitingAskFactProvider implements FactProvider
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxAvailability $availability,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WaitingAskFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        if (!$this->availability->isEnabled()) {
            return new WaitingAskFacts([]);
        }

        return new WaitingAskFacts($this->inboxAsks->findIdsWaitingOnBlockedRunOfCard($card->projectId, $card->id));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WaitingAskFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->askIds;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.inbox';
    }
}
