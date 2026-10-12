<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Controller\ShowCardController;
use App\Module\Inbox\Command\ShowInboxCommand;
use App\Module\Inbox\Command\ShowInboxHandler;
use App\Module\Inbox\Controller\ShowInboxController;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxLinkedPage;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\View\InboxReturnTarget;
use App\Module\Inbox\View\InboxRow;
use App\Module\Inbox\View\InboxSide;
use App\Module\Inbox\View\InboxView;
use App\Module\Project\Entity\Project;
use App\Module\Review\Controller\ShowDocumentController;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the page a response form came from out of its query string.
 *
 * Only a card or document the item links to is accepted, and the route is
 * always built here, so the query can never send the owner to an address of its
 * choosing. Anything else returns to the inbox. The check runs before the
 * response is saved, so a saved response never redirects to a missing page.
 */
final readonly class InboxReturnTargetResolver
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private InboxAskRepository $inboxAsks,
        private ShowInboxHandler $inboxPage,
    ) {
    }

    /**
     * @param ?InboxReturnTarget $before the target read before the action. With it, the inbox target opens the next ask.
     */
    public function resolve(Request $request, InboxItem $item, ?InboxReturnTarget $before = null): InboxReturnTarget
    {
        $projectId = (string) $item->project->id;
        $page = InboxLinkedPage::tryFrom($request->query->getString(InboxReturnTarget::PAGE_QUERY));
        $rawId = $request->query->getString(InboxReturnTarget::ID_QUERY);
        $id = Uuid::isValid($rawId) ? Uuid::fromString($rawId) : null;

        if (InboxLinkedPage::Card === $page && null !== $id && $item->cards->exists(static fn (int $key, InboxItemCard $link): bool => (bool) $link->card->id?->equals($id))) {
            $target = ['projectId' => $projectId, 'cardId' => $id->toRfc4122()];

            return new InboxReturnTarget('app_board_card', [...$target, 'tab' => 'conversation'], ShowCardController::class, $target, ['tab' => 'conversation']);
        }

        $document = InboxLinkedPage::Document === $page && null !== $id ? $this->linkedDocument($item, $id) : null;
        if (null !== $document && null !== $id) {
            $target = ['projectId' => $projectId, 'documentId' => $id->toRfc4122()];
            // An older version is a page of its own, and the owner stays on it. A
            // version the document lacks returns to the current one instead.
            $version = $request->query->getInt(InboxReturnTarget::VERSION_QUERY);
            if ($version >= 1 && 1 === $this->documentVersions->countByNumbers($document, [$version])) {
                $target['versionNumber'] = $version;

                return new InboxReturnTarget('app_document_review_version', $target, ShowDocumentController::class, $target, []);
            }

            return new InboxReturnTarget('app_document_review', $target, ShowDocumentController::class, $target, []);
        }

        // The view the owner was on, kept on the way back.
        $view = InboxView::fromQuery($request->query);
        // Read as objects, so an ask closed in this request counts as closed.
        $asks = $this->inboxAsks->findHolding($item);
        $closed = InboxItemState::Open !== $item->state;

        // Only the open list moves on to a next ask. A search or the closed side keeps the ask the owner acted on.
        $ordered = InboxSide::Open === $view->side && !$view->isSearch();
        $rowKeys = $ordered ? ($before->rowKeys ?? $this->rowKeys($item->project, $view)) : [];
        $acted = $ordered ? $this->actedRow($view, $item, $asks, $rowKeys) : null;
        // The status line names the ask in every view, also where the list does not move on.
        $actedAsk = $ordered ? ($acted[1] ?? null) : $this->heldAsk($view, $asks);

        if (null !== $before && null !== $acted) {
            $view = $this->nextView($item, $view, $before->rowKeys, $acted);
            $pageQuery = $view->toQuery();

            return new InboxReturnTarget(InboxReturnTarget::INBOX_ROUTE, ['id' => $projectId, ...$pageQuery], ShowInboxController::class, ['id' => $projectId, 'project' => $item->project], $pageQuery, $rowKeys, $acted[1]);
        }

        // The pane opens on the request the address names, so it is named only
        // where the page shows it. A closed item that no ask holds is on neither
        // side, and naming it would send the owner hunting for it instead of
        // back to the work that is left. A forward re-renders the page the form
        // was already on, so it needs no name.
        $shown = $view->isSearch() || !$closed || [] !== $asks;
        if (!$shown) {
            $view = $view->withoutSelection();
        } elseif (!$view->isPicked()) {
            $view = $view->withItem($item->number);
        }

        $pageQuery = $view->toQuery();

        return new InboxReturnTarget(InboxReturnTarget::INBOX_ROUTE, ['id' => $projectId, ...$pageQuery], ShowInboxController::class, ['id' => $projectId, 'project' => $item->project], $pageQuery, $rowKeys, $actedAsk);
    }

    /**
     * The keys of the rows the view lists, in list order.
     *
     * @return list<string>
     */
    private function rowKeys(Project $project, InboxView $view): array
    {
        return array_map(static fn (InboxRow $row): string => $row->key(), ($this->inboxPage)(new ShowInboxCommand($project, $view))->rows);
    }

    /**
     * The row of the open list the owner acted on, with its ask. The row the
     * address selects wins. An item that sits in two asks, or an address that
     * names no row, falls back to the first open row that holds the item.
     *
     * @param list<InboxAsk> $asks    every ask that holds the item
     * @param list<string>   $rowKeys the open list before the action
     *
     * @return ?array{string, ?InboxAsk}
     */
    private function actedRow(InboxView $view, InboxItem $item, array $asks, array $rowKeys): ?array
    {
        $selected = null !== $view->askId ? (string) $view->askId : (null !== $view->itemNumber ? 'item-'.$view->itemNumber : null);
        if (null !== $selected && \in_array($selected, $rowKeys, true)) {
            $ask = array_find($asks, static fn (InboxAsk $held): bool => (string) $held->id === $selected);

            return [$selected, $ask];
        }

        foreach ($asks as $ask) {
            if (\in_array((string) $ask->id, $rowKeys, true)) {
                return [(string) $ask->id, $ask];
            }
        }

        return \in_array('item-'.$item->number, $rowKeys, true) ? ['item-'.$item->number, null] : null;
    }

    /**
     * The ask the address names when it holds the item, else the oldest ask that holds it.
     *
     * @param list<InboxAsk> $asks every ask that holds the item
     */
    private function heldAsk(InboxView $view, array $asks): ?InboxAsk
    {
        return array_find($asks, static fn (InboxAsk $held): bool => null !== $view->askId && (string) $held->id === (string) $view->askId) ?? $asks[0] ?? null;
    }

    /**
     * The acted row stays selected while it is open. Otherwise the next row
     * of the list opens, and an empty list names none.
     *
     * @param list<string>             $before the open list before the action
     * @param array{string, ?InboxAsk} $acted
     */
    private function nextView(InboxItem $item, InboxView $view, array $before, array $acted): InboxView
    {
        $after = $this->rowKeys($item->project, $view);
        $key = \in_array($acted[0], $after, true) ? $acted[0] : NextInboxRow::after($before, $after, $acted[0]);

        return null === $key ? $view->withoutSelection() : NextInboxRow::select($view, $key);
    }

    private function linkedDocument(InboxItem $item, Uuid $id): ?Document
    {
        $link = $item->documents->findFirst(static fn (int $key, InboxItemDocument $link): bool => (bool) $link->document->id?->equals($id));

        return $link?->document;
    }
}
