<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\View\InboxThreadClosing;
use App\Module\Inbox\View\InboxThreadReply;
use App\Module\Inbox\View\InboxThreadView;

/** Builds the thread of one item from the rows that already exist. */
final readonly class InboxThreadBuilder
{
    public function __construct(
        private InboxCardWatchRepository $inboxCardWatches,
        private CardWaitText $cardWaitText,
        private InboxActionStatuses $actionStatuses,
        private InboxOutcome $outcome,
        private ReplyCardBuilder $replyCards,
    ) {
    }

    /** @param ?string $hostCardId the card whose page shows the item, so the thread offers no link back to it */
    public function build(InboxItem $item, ?string $hostCardId = null): InboxThreadView
    {
        $watch = $item->isAutomatic() ? $this->inboxCardWatches->findOneForItem($item) : null;
        $statuses = $this->actionStatuses->forItems([$item])[(string) $item->id] ?? [];
        $last = array_last($statuses);
        // An action under way answers the wait, so the card stays away until it expires, is refused or is cancelled.
        $answering = null !== $last && $last->answers($watch->waits ?? []);

        $closed = InboxItemState::Open !== $item->state;
        $byOwner = \in_array($item->closedBy, [InboxCloser::Owner, InboxCloser::OwnerThroughAgent], true);
        $note = null === $item->note || '' === trim($item->note) ? null : $item->note;
        $body = $item->isAutomatic() || '' === trim($item->body ?? '') ? null : $item->body;

        return new InboxThreadView(
            item: $item,
            body: $body,
            watch: $watch,
            waits: null === $watch ? [] : $this->cardWaitText->pageWaits($watch->waits),
            actions: $statuses,
            reply: $closed && $byOwner ? $this->reply($item, $note) : null,
            closing: $closed && !$byOwner ? new InboxThreadClosing($item->closedBy, $this->outcome->forItem($item), $item->closedAt, $note) : null,
            replyCard: $answering ? null : $this->replyCards->build($item, $hostCardId),
            refusalKey: $this->replyCards->refusalFor($item),
        );
    }

    private function reply(InboxItem $item, ?string $note): InboxThreadReply
    {
        $verb = match (true) {
            InboxItemState::Declined === $item->state => 'dismiss',
            InboxItemKind::Review === $item->kind => 'submit-review',
            \in_array($item->kind, [InboxItemKind::Question, InboxItemKind::Workflow], true) => 'answer',
            default => 'complete',
        };

        return new InboxThreadReply(
            verbKey: 'inbox.thread.you_verb.'.$verb,
            at: $item->closedAt,
            outcome: $this->outcome->forItem($item),
            options: array_map(static fn (int $index): string => (string) ($item->options[$index] ?? $index), $item->selectedOptions),
            note: $note,
            throughAgent: InboxCloser::OwnerThroughAgent === $item->closedBy,
        );
    }
}
