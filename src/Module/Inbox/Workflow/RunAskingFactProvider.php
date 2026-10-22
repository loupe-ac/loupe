<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

/** An inbox that is off holds no ask, so the run is not asking. */
final readonly class RunAskingFactProvider implements FactProvider
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxAvailability $availability,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return RunAskingFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $row = $this->availability->isEnabled() ? $this->inboxAsks->findAskOfNewestRunOfCard($card->projectId, $card->id) : null;
        if (null === $row) {
            return new RunAskingFacts(false, null);
        }
        if (null === $row['closed_at']) {
            return new RunAskingFacts(true, null);
        }

        // The resume queues at the close, or at the end of a run that still ran then, whichever came last.
        $closed = new \DateTimeImmutable($row['closed_at']);
        $since = null === $row['ended_at'] ? $closed : max($closed, new \DateTimeImmutable($row['ended_at']));

        return new RunAskingFacts(false, $since->modify(RunAskingFacts::RESUME_GRACE));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof RunAskingFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return [$facts->askOpen, $facts->graceEndsAt?->format(\DATE_ATOM)];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.inbox';
    }
}
