<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\View\InboxThreadActions;
use App\Module\Inbox\View\ItemAction;
use App\Module\Inbox\View\ThreadAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns the actions that fit an item into the rows of its split button. Open and Review are plain
 * links, so this is where their address is computed. An action whose address is missing is left out,
 * because a row that goes nowhere is worse than no row.
 */
final readonly class InboxThreadActionBuilder
{
    public function __construct(
        private ItemActions $itemActions,
        private InboxCardWatchRepository $inboxCardWatches,
        private CardWaitText $cardWaitText,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** @param ?string $hostCardId the card whose page shows the item, so the thread offers no link back to it */
    public function build(InboxItem $item, ?string $hostCardId = null): InboxThreadActions
    {
        return $this->fromActions($item, $this->itemActions->forItem($item), $hostCardId);
    }

    /**
     * @param list<ItemAction> $actions    what ItemActions gives for the item, so a caller that holds them reads them once
     * @param ?string          $hostCardId the card whose page shows the item, so the thread offers no link back to it
     */
    public function fromActions(InboxItem $item, array $actions, ?string $hostCardId = null): InboxThreadActions
    {
        $rows = [];
        $dismiss = null;
        $default = null;
        foreach ($actions as $action) {
            // The response forms of the page carry these, so the split button has a row for Dismiss on a wait item alone.
            if ($action->kind->isResponse() && !(InboxActionKind::Dismiss === $action->kind && $item->isAutomatic())) {
                continue;
            }
            $row = $this->row($item, $action, $hostCardId);
            if (null === $row) {
                continue;
            }
            if (InboxActionKind::Dismiss === $action->kind) {
                $dismiss = $row;
            } else {
                $rows[] = $row;
            }
            // A run-command action stays the main button when it is off, because its reason and its dialog belong beside the button.
            if ($action->default && InboxActionKind::Dismiss !== $action->kind && (null === $action->disabledReasonKey || InboxActionKind::RunCommand === $action->kind)) {
                $default = $row;
            }
        }

        $enabled = array_values(array_filter($rows, static fn (ThreadAction $row): bool => null === $row->action->disabledReasonKey));
        $main = $default ?? ($enabled[0] ?? null);

        return new InboxThreadActions($main, array_values(array_filter($rows, static fn (ThreadAction $row): bool => $row !== $main)), $dismiss);
    }

    private function row(InboxItem $item, ItemAction $action, ?string $hostCardId): ?ThreadAction
    {
        return match ($action->kind) {
            InboxActionKind::Open => $this->openRow($item, $action, $hostCardId),
            InboxActionKind::Review => $this->reviewRow($item, $action),
            InboxActionKind::OpenPullRequest, InboxActionKind::ApprovePullRequest => null === $action->href ? null : new ThreadAction($action, $action->href, external: true),
            default => new ThreadAction($action),
        };
    }

    private function openRow(InboxItem $item, ItemAction $action, ?string $hostCardId): ?ThreadAction
    {
        $cardId = $this->itemActions->cardIdOf($item, InboxActionKind::Open);
        if (null !== $cardId) {
            if ($cardId->toRfc4122() === $hostCardId) {
                return null;
            }

            return new ThreadAction(self::openLabelled($action, 'card'), $this->urls->generate('app_board_card', ['projectId' => $item->project->id, 'cardId' => $cardId]), card: true);
        }

        $document = $item->documents->first();
        if (!$document instanceof InboxItemDocument) {
            return null;
        }

        return new ThreadAction(self::openLabelled($action, 'document'), $this->urls->generate('app_document_review', ['projectId' => $item->project->id, 'documentId' => $document->document->id]));
    }

    /** A bare "Open" does not say what it opens, so the row names its target. */
    private static function openLabelled(ItemAction $action, string $target): ItemAction
    {
        return new ItemAction($action->kind, \sprintf('inbox.action.open.%s.label', $target), \sprintf('inbox.action.open.%s.effect', $target), $action->default, $action->disabledReasonKey, $action->reason, $action->runCommand, $action->sessionPrompt, $action->labelParams, $action->href);
    }

    private function reviewRow(InboxItem $item, ItemAction $action): ?ThreadAction
    {
        $watch = $this->inboxCardWatches->findOneForItem($item);
        if (null === $watch) {
            return null;
        }
        $linked = $item->documents->map(static fn (InboxItemDocument $link): string => (string) $link->document->id)->toArray();
        $waits = array_values(array_filter(
            $watch->waits->toArray(),
            static fn (InboxCardWait $wait): bool => null === $wait->endedAt && \in_array($wait->type, [InboxCardWaitType::Document, InboxCardWaitType::PullRequest], true),
        ));
        usort($waits, static fn (InboxCardWait $a, InboxCardWait $b): int => [$b->startedAt, $b->key()] <=> [$a->startedAt, $a->key()]);

        foreach ($waits as $wait) {
            $href = $this->cardWaitText->line($wait, null !== $wait->documentId && \in_array($wait->documentId->toRfc4122(), $linked, true))->href;
            if (null !== $href) {
                return new ThreadAction($action, $href, external: InboxCardWaitType::PullRequest === $wait->type);
            }
        }

        return null;
    }
}
