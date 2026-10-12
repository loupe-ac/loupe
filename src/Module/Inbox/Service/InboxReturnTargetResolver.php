<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Controller\ShowCardController;
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
use App\Module\Inbox\View\InboxSide;
use App\Module\Inbox\View\InboxView;
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
    ) {
    }

    public function resolve(Request $request, InboxItem $item): InboxReturnTarget
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
        $allClosed = $closed && [] !== $asks && [] === array_filter($asks, static fn (InboxAsk $ask): bool => null === $ask->closedAt);

        // A side switch leaves the page and the type filter behind, because the
        // page the owner was on numbers the other side, and the page of the
        // closed ask is counted over every type. The closed ask the view names
        // decides which closed page holds this item.
        if ($allClosed && !$view->isSearch() && InboxSide::Open === $view->side) {
            $anchor = array_find($asks, static fn (InboxAsk $ask): bool => null !== $view->askId && ($ask->id?->equals($view->askId) ?? false)) ?? $this->firstClosed($asks);
            $view = new InboxView(InboxSide::Closed, null, '', $view->askId, $view->itemNumber, $this->inboxAsks->closedPageOf($anchor, ShowInboxHandler::CLOSED_ASKS_PER_PAGE));
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

        return new InboxReturnTarget('app_project_inbox', ['id' => $projectId, ...$pageQuery], ShowInboxController::class, ['id' => $projectId, 'project' => $item->project], $pageQuery);
    }

    /**
     * The ask the closed side lists first, which is the newest close.
     *
     * @param list<InboxAsk> $asks all closed, because the caller switches sides
     */
    private function firstClosed(array $asks): InboxAsk
    {
        usort($asks, static fn (InboxAsk $a, InboxAsk $b): int => ($b->closedAt <=> $a->closedAt) ?: ((string) $a->id <=> (string) $b->id));

        return $asks[0] ?? throw new \LogicException('A closed side switch needs the closed ask that holds the item.');
    }

    private function linkedDocument(InboxItem $item, Uuid $id): ?Document
    {
        $link = $item->documents->findFirst(static fn (int $key, InboxItemDocument $link): bool => (bool) $link->document->id?->equals($id));

        return $link?->document;
    }
}
