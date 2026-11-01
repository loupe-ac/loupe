<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewTargetKind;
use App\Module\Inbox\View\ItemAction;
use App\Module\Inbox\View\ReplyCard;
use App\Module\Inbox\View\ReplyField;
use App\Module\Inbox\View\ThreadAction;

/**
 * Builds the card where the owner answers an open item. A sent response is final, so a closed item has no card
 * and a late response gets the refusal text instead.
 */
final readonly class ReplyCardBuilder
{
    public function __construct(
        private ItemActions $itemActions,
        private InboxThreadActionBuilder $threadActions,
        private InboxReviewLookup $inboxReviews,
    ) {
    }

    /** @param ?string $hostCardId the card whose page shows the item, so the card offers no link back to it */
    public function build(InboxItem $item, ?string $hostCardId = null): ?ReplyCard
    {
        if (InboxItemState::Open !== $item->state) {
            return null;
        }

        $actions = $this->itemActions->forItem($item);
        $rows = $this->threadActions->fromActions($item, $actions, $hostCardId);
        $review = InboxItemKind::Review === $item->kind && !$item->isAutomatic() ? $this->inboxReviews->forItem($item) : null;
        $targetGone = InboxItemKind::Review === $item->kind && !$item->isAutomatic() && self::targetIsGone($review);

        $response = $targetGone ? null : array_find($actions, static fn (ItemAction $action): bool => \in_array($action->kind, [InboxActionKind::Answer, InboxActionKind::Complete, InboxActionKind::SubmitReview], true));
        $dismiss = \in_array($item->kind, [InboxItemKind::Notice, InboxItemKind::Workflow], true)
            ? null
            : array_find($actions, static fn (ItemAction $action): bool => InboxActionKind::Dismiss === $action->kind);

        if (null === $response) {
            $main = $rows->main;
            $menu = $rows->menu;
        } else {
            $main = new ThreadAction($response);
            $menu = array_values(array_filter([$rows->main, ...$rows->menu]));
        }
        $dismissRow = null === $dismiss ? null : new ThreadAction($dismiss);

        $fields = $this->fields($item, $targetGone);
        if ([] === $fields && null === $main && [] === $menu && null === $dismissRow) {
            return null;
        }

        return new ReplyCard($fields, $this->hintKey($item, $fields, $review), $main, $menu, $dismissRow, $review, $targetGone);
    }

    /** What a response to the item gets once it is closed, or null while it is open. */
    public function refusalFor(InboxItem $item): ?string
    {
        return InboxItemState::Open === $item->state ? null : InboxItemCloser::ERROR_FINAL;
    }

    /** @return list<ReplyField> */
    private function fields(InboxItem $item, bool $targetGone): array
    {
        if ($item->isAutomatic()) {
            return [ReplyField::Note];
        }

        return match ($item->kind) {
            InboxItemKind::Question, InboxItemKind::Workflow => [
                ...([] === $item->options ? [] : [ReplyField::Options]),
                ...($item->takesNote() ? [ReplyField::Note] : []),
            ],
            InboxItemKind::Todo => [ReplyField::Note],
            InboxItemKind::Review => $targetGone ? [] : [ReplyField::Verdict, ReplyField::Note],
            default => [],
        };
    }

    /** @param list<ReplyField> $fields */
    private function hintKey(InboxItem $item, array $fields, ?InboxReview $review): ?string
    {
        if ($item->isAutomatic()) {
            return \in_array(ReplyField::Note, $fields, true) ? 'inbox.thread.note_hint' : null;
        }

        return match (true) {
            \in_array($item->kind, [InboxItemKind::Question, InboxItemKind::Workflow], true) => 'inbox.answer.final_hint',
            InboxReviewTargetKind::PullRequest === $review?->targetKind && null !== $review->pullRequest => 'inbox.review.local_only',
            default => null,
        };
    }

    private static function targetIsGone(?InboxReview $review): bool
    {
        if (null === $review) {
            return true;
        }

        return InboxReviewTargetKind::Document === $review->targetKind ? null === $review->document : null === $review->pullRequest;
    }
}
