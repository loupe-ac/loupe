<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\View\InboxOutcomeLabel;

/** Says how a closed item ended, from its state, kind, review verdict, closer and cause. */
final readonly class InboxOutcome
{
    public function __construct(
        private InboxReviewLookup $inboxReviews,
    ) {
    }

    /** Null for an open item. */
    public function forItem(InboxItem $item): ?InboxOutcomeLabel
    {
        return $this->label($item, $this->verdict($item));
    }

    /** The state alone, or the verdict of a review, for a place that already names who closed the item and why. */
    public function shortFor(InboxItem $item): ?InboxOutcomeLabel
    {
        return $this->verdictFor($item) ?? match ($item->state) {
            InboxItemState::Open => null,
            InboxItemState::Resolved => new InboxOutcomeLabel('inbox.outcome.resolved'),
            InboxItemState::Declined => new InboxOutcomeLabel('inbox.outcome.declined'),
            InboxItemState::Cancelled => new InboxOutcomeLabel('inbox.outcome.cancelled'),
        };
    }

    /** The verdict of a resolved review, or null for any other item. */
    public function verdictFor(InboxItem $item): ?InboxOutcomeLabel
    {
        return match ($this->verdict($item)) {
            InboxReviewVerdict::Approved => new InboxOutcomeLabel('inbox.outcome.verdict.approved'),
            InboxReviewVerdict::ChangesRequested => new InboxOutcomeLabel('inbox.outcome.verdict.changes_requested'),
            null => null,
        };
    }

    public function label(InboxItem $item, ?InboxReviewVerdict $verdict): ?InboxOutcomeLabel
    {
        return match ($item->state) {
            InboxItemState::Open => null,
            InboxItemState::Resolved => new InboxOutcomeLabel($this->resolvedKey($item, $verdict)),
            InboxItemState::Declined => new InboxOutcomeLabel(match ($item->closedBy) {
                InboxCloser::Owner => 'inbox.outcome.owner.dismissed',
                InboxCloser::OwnerThroughAgent => 'inbox.outcome.agent_for_owner.dismissed',
                default => 'inbox.outcome.declined',
            }),
            InboxItemState::Cancelled => new InboxOutcomeLabel($this->cancelledKey($item)),
        };
    }

    private function verdict(InboxItem $item): ?InboxReviewVerdict
    {
        return InboxItemState::Resolved === $item->state && InboxItemKind::Review === $item->kind
            ? $this->inboxReviews->forItem($item)?->verdict
            : null;
    }

    private function resolvedKey(InboxItem $item, ?InboxReviewVerdict $verdict): string
    {
        $what = match (true) {
            InboxReviewVerdict::Approved === $verdict => 'approved',
            InboxReviewVerdict::ChangesRequested === $verdict => 'changes_requested',
            \in_array($item->kind, [InboxItemKind::Question, InboxItemKind::Workflow], true) => 'answered',
            default => 'done',
        };

        return match ($item->closedBy) {
            InboxCloser::Owner => 'inbox.outcome.owner.'.$what,
            InboxCloser::OwnerThroughAgent => 'inbox.outcome.agent_for_owner.'.$what,
            InboxCloser::Rule => null === $verdict ? 'inbox.outcome.rule.resolved' : 'inbox.outcome.rule.'.$what,
            InboxCloser::Loupe => InboxCloseCause::CommandSucceeded === $item->closeCause ? 'inbox.outcome.loupe.command_succeeded' : 'inbox.outcome.loupe.resolved',
            default => 'inbox.outcome.resolved',
        };
    }

    private function cancelledKey(InboxItem $item): string
    {
        return match ($item->closedBy) {
            InboxCloser::Agent => 'inbox.outcome.agent.withdrawn',
            InboxCloser::Loupe, InboxCloser::Rule => match ($item->closeCause) {
                InboxCloseCause::CardDeleted => 'inbox.outcome.loupe.card_deleted',
                InboxCloseCause::CardFinished => 'inbox.outcome.loupe.card_finished',
                InboxCloseCause::RuleWithdrew => 'inbox.outcome.loupe.rule_withdrew',
                InboxCloseCause::SwitchedOff => 'inbox.outcome.loupe.switched_off',
                default => 'inbox.outcome.loupe.cancelled',
            },
            default => 'inbox.outcome.cancelled',
        };
    }
}
