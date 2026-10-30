<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Inbox\Service\ShownInboxAsk;
use App\Module\Inbox\View\InboxPageView;
use App\Module\Inbox\View\InboxRow;
use App\Module\Inbox\View\InboxSide;
use App\Module\Inbox\View\InboxView;
use App\Module\Project\Entity\Project;
use App\Utils\PageList;

final readonly class ShowInboxHandler
{
    public const int CLOSED_ASKS_PER_PAGE = 10;

    public const int SEARCH_RESULTS_PER_PAGE = 25;

    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
        private BridgeLiveness $bridgeLiveness,
        private InboxReviewLookup $inboxReviews,
    ) {
    }

    public function __invoke(ShowInboxCommand $command): InboxPageView
    {
        $project = $command->project;
        $view = $command->view;

        $openRows = $this->openRows($project);

        if ($view->isSearch()) {
            $search = $this->inboxAsks->searchRows($project, $view->query, $view->type, $this->inboxItems->searchLanguagesOf($project), $view->page, self::SEARCH_RESULTS_PER_PAGE);
            $rows = $this->rowsOf($search->rows);
            $total = $search->total;
            $page = $search->page;
            $perPage = self::SEARCH_RESULTS_PER_PAGE;
        } elseif (InboxSide::Closed === $view->side) {
            $total = $this->inboxAsks->countClosedForProject($project, $view->type);
            $perPage = self::CLOSED_ASKS_PER_PAGE;
            $page = min(max(1, $view->page), max(1, (int) ceil($total / $perPage)));
            $rows = $this->rowsOf($this->inboxAsks->findClosedPageForProject($project, ($page - 1) * $perPage, $perPage, $view->type));
        } else {
            $rows = null === $view->type ? $openRows : array_values(array_filter($openRows, static fn (InboxRow $row): bool => $row->holdsKind($view->type)));
            $total = \count($rows);
            $page = 1;
            $perPage = max(1, $total);
        }

        $view = $view->withPage($page);
        $selected = $this->select($project, $view, $rows);

        $selectedItems = $selected->items ?? [];
        $bridgeId = $selected?->ask?->bridgeId;
        $totalPages = max(1, (int) ceil($total / $perPage));

        return new InboxPageView(
            project: $project,
            view: $view,
            rows: $rows,
            selected: $selected,
            openCount: \count($openRows),
            total: $total,
            page: $page,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            finalItemIds: array_fill_keys($this->inboxAsks->findItemIdsHeldByClosedAsks($selectedItems), true),
            bridgeStatuses: null === $bridgeId || null !== $selected?->ask?->closedAt ? [] : $this->bridgeLiveness->forOwner($project->owner, [$bridgeId]),
            pickedNothing: $view->isPicked() && null === $selected,
        );
    }

    /**
     * The open side whole: rows with an open blocking item first, then the
     * others, each group oldest first.
     *
     * @return list<InboxRow>
     */
    private function openRows(Project $project): array
    {
        $rows = $this->rowsOf([...$this->inboxAsks->findOpenForProject($project), ...$this->inboxItems->findOpenOutsideOpenAsks($project)]);

        usort($rows, static fn (InboxRow $a, InboxRow $b): int => [$a->blocking ? 0 : 1, $a->createdAt(), $a->key()] <=> [$b->blocking ? 0 : 1, $b->createdAt(), $b->key()]);

        return $rows;
    }

    /**
     * @param list<InboxAsk|InboxItem> $entities
     *
     * @return list<InboxRow>
     */
    private function rowsOf(array $entities): array
    {
        $items = [];
        foreach ($entities as $entity) {
            foreach ($entity instanceof InboxAsk ? $this->itemsOfAsk($entity) : [$entity] as $item) {
                $items[(string) $item->id] = $item;
            }
        }
        $items = array_values($items);
        $this->inboxItems->loadCards($items);
        $this->inboxReviews->preload($items);

        $rows = [];
        foreach ($entities as $entity) {
            $row = $entity instanceof InboxAsk ? $this->rowOfAsk($entity) : $this->rowOfItem($entity);
            if (null !== $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The row the pane shows. The address names an ask, or an item that
     * resolves to one, and a named ask shows even when the list does not hold it.
     *
     * @param list<InboxRow> $rows
     */
    private function select(Project $project, InboxView $view, array $rows): ?InboxRow
    {
        if (null === $view->askId && null === $view->itemNumber) {
            return $rows[0] ?? null;
        }

        $ask = null;
        $loose = null;
        if (null !== $view->askId) {
            $ask = $this->inboxAsks->findOneByIdAndProject($view->askId, $project);
        } elseif (null !== $view->itemNumber) {
            $item = $this->inboxItems->findOneByProjectAndNumber($project, $view->itemNumber);
            $ask = null === $item ? null : $this->askOfItem($item);
            $loose = null === $item || null !== $ask ? null : $item;
        }

        if (null !== $ask) {
            return array_find($rows, static fn (InboxRow $row): bool => $row->ask?->id?->equals($ask->id) ?? false) ?? $this->rowsOf([$ask])[0] ?? null;
        }

        if (null !== $loose) {
            return array_find($rows, static fn (InboxRow $row): bool => $row->isLoose() && ($row->items[0]->id?->equals($loose->id) ?? false)) ?? $this->rowsOf([$loose])[0];
        }

        return null;
    }

    /** An open ask that holds the item, else the newest closed one, else null. */
    private function askOfItem(InboxItem $item): ?InboxAsk
    {
        return ShownInboxAsk::of($this->inboxAsks->findHolding($item));
    }

    private function rowOfAsk(InboxAsk $ask): ?InboxRow
    {
        $items = $this->itemsOfAsk($ask);
        if ([] === $items) {
            return null;
        }

        if (null === $ask->closedAt) {
            return new InboxRow($ask, $items);
        }
        $closing = $this->closingItemOf($items);

        return new InboxRow($ask, $items, $this->outcomeOf($closing), $closing);
    }

    /**
     * The last blocking item to close is the one that closed the ask. An ask
     * with no closed blocking item falls back to its last closed item, then its first.
     *
     * @param non-empty-list<InboxItem> $items
     */
    private function closingItemOf(array $items): InboxItem
    {
        $closed = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemState::Open !== $item->state));
        $blocking = array_values(array_filter($closed, static fn (InboxItem $item): bool => $item->blocking));
        $last = null;
        foreach ([] === $blocking ? $closed : $blocking as $item) {
            if (null === $last || $item->closedAt > $last->closedAt) {
                $last = $item;
            }
        }

        return $last ?? $items[0];
    }

    private function rowOfItem(InboxItem $item): InboxRow
    {
        return new InboxRow(null, [$item], $this->outcomeOf($item), InboxItemState::Open === $item->state ? null : $item);
    }

    /** How a closed row ended, read from the item that closed it. A row that is still open has none. */
    private function outcomeOf(InboxItem $item): ?string
    {
        return match ($item->state) {
            InboxItemState::Open => null,
            InboxItemState::Resolved => match ($this->inboxReviews->forItem($item)?->verdict) {
                InboxReviewVerdict::Approved => 'approved',
                InboxReviewVerdict::ChangesRequested => 'changes_requested',
                null => $item->stateLabelKey(),
            },
            InboxItemState::Declined => $item->isAutomatic() ? 'dismissed' : 'declined',
            InboxItemState::Cancelled => $item->stateLabelKey(),
        };
    }

    /** @return list<InboxItem> */
    private function itemsOfAsk(InboxAsk $ask): array
    {
        return array_values($ask->items->map(static fn (InboxAskItem $link): InboxItem => $link->item)->toArray());
    }
}
