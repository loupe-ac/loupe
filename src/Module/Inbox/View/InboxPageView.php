<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Bridge\View\BridgeStatus;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;

/** The project inbox page: the rows of one view, and the row the pane shows. */
final readonly class InboxPageView implements InboxItemsView
{
    /**
     * @param list<InboxRow>              $rows           the list as the view filters it, one page of it on the closed side and in a search
     * @param ?InboxRow                   $selected       the row the pane shows, which the list need not hold
     * @param int                         $openCount      every open row, whatever the filter
     * @param int                         $closedCount    every closed ask, whatever the filter
     * @param int                         $total          the rows the view matches, over all its pages
     * @param list<int|null>              $pageList       page numbers, null for a gap
     * @param array<string, true>         $finalItemIds   items of the selected row that a closed ask holds
     * @param array<string, BridgeStatus> $bridgeStatuses the bridge of the selected ask, by bridge id
     * @param bool                        $pickedNothing  the address named an ask or an item that the project does not hold
     */
    public function __construct(
        public Project $project,
        public InboxView $view,
        public array $rows,
        public ?InboxRow $selected,
        public int $openCount,
        public int $closedCount,
        public int $total,
        public int $page,
        public int $totalPages,
        public array $pageList,
        public array $finalItemIds,
        public array $bridgeStatuses,
        public bool $pickedNothing = false,
    ) {
    }

    #[\Override]
    public function isHome(InboxItem $item, ?InboxAsk $ask = null): bool
    {
        if (null === $this->selected || $this->selected->ask?->id?->toRfc4122() !== $ask?->id?->toRfc4122()) {
            return false;
        }

        return null !== array_find($this->selected->items, static fn (InboxItem $held): bool => $held->id?->equals($item->id) ?? false);
    }

    #[\Override]
    public function acceptsResponse(InboxItem $item): bool
    {
        if (InboxItemKind::Notice === $item->kind || (\in_array($item->kind, [InboxItemKind::Review, InboxItemKind::Workflow], true) && InboxItemState::Open !== $item->state)) {
            return false;
        }

        return $item->state->acceptsResponse(isset($this->finalItemIds[(string) $item->id]));
    }

    /** The view the owner is on, with the row the pane shows named, so a response returns to it. */
    #[\Override]
    public function actionQuery(): array
    {
        return ($this->selected?->pickedIn($this->view) ?? $this->view->withoutSelection())->toQuery();
    }

    /** Null for a closed ask, or for an interactive session that no bridge resumes. */
    public function bridgeStatus(InboxAsk $ask): ?BridgeStatus
    {
        if (null !== $ask->closedAt || null === $ask->bridgeId) {
            return null;
        }

        return $this->bridgeStatuses[$ask->bridgeId->toRfc4122()]
            ?? throw new \LogicException('The inbox handler reads the bridge of the selected ask.');
    }
}
