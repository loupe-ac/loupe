<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxCardWait;

/** The status line of one action. The label key and its parameters go to the translator. */
final readonly class InboxActionStatus
{
    /** @param array<string, string> $labelParameters */
    public function __construct(
        public InboxAction $action,
        public InboxActionState $state,
        public string $labelKey,
        public array $labelParameters = [],
        public bool $cancellable = false,
        /** True while no live bridge can take a queued command. */
        public bool $offline = false,
        /** When the state began. */
        public ?\DateTimeImmutable $at = null,
    ) {
    }

    /**
     * Whether the action still answers the open waits, so the thread hides the reply card.
     * Times hold whole seconds, so a wait that starts in the second of the click is new unless it is the answered one.
     *
     * @param iterable<InboxCardWait> $waits
     */
    public function answers(iterable $waits): bool
    {
        $open = array_filter([...$waits], static fn (InboxCardWait $wait): bool => null === $wait->endedAt);
        if (!$this->state->inProgress() || [] === $open) {
            return false;
        }
        $requestedAt = $this->action->requestedAt->getTimestamp();

        return !array_any($open, fn (InboxCardWait $wait): bool => $wait->startedAt->getTimestamp() >= $requestedAt && true !== $wait->id?->equals($this->action->waitId));
    }
}
