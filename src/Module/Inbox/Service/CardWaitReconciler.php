<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Contract\MarkerSubjectType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the inbox set that the workflow engine stored on each card, compares it with the open wait item
 * of the card, and writes the difference. Running it twice changes nothing, so any trigger may ask for it again.
 */
final readonly class CardWaitReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private CardRepository $cards,
        private InboxCardWatchRepository $inboxCardWatches,
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxSearchIndexer $searchIndexer,
        private InboxChangedPublisher $inboxChanged,
        private InboxAvailability $inbox,
        private WorkerRunRepository $workerRuns,
        private DocumentRepository $documents,
        private CardWaitText $waitText,
        private CardMarkerStore $markers,
        private MessageBusInterface $bus,
    ) {
    }

    /** @param list<string>|null $cardIds null for every card that has an open watch or a stored inbox entry */
    public function reconcile(Project $project, ?array $cardIds): void
    {
        [$countChanged, $pageChanged] = $this->em->wrapInTransaction(function () use ($project, $cardIds): array {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $ids = $this->cardIds($project, $cardIds);
            if ([] === $ids) {
                return [false, false];
            }

            $now = self::now();
            $enabled = $this->inbox->isEnabled();
            $cards = [];
            foreach ($this->cards->findByIdsInProject($project, array_values($ids)) as $card) {
                $cards[(string) $card->id] = $card;
            }
            $watches = [];
            foreach ($this->inboxCardWatches->findOpenForCards($project, array_values($ids)) as $watch) {
                $watches[(string) $watch->cardId] = $watch;
            }

            // Doctrine inserts before it updates, so a watch must be closed in
            // the database before a new one of the same card can be inserted.
            $stale = false;
            foreach ($watches as $cardId => $watch) {
                if (InboxItemState::Open !== $watch->item->state) {
                    $reason = null === $watch->dismissedAt ? $this->cause($cards[$cardId] ?? null, $enabled) : InboxCardWaitEndReason::Dismissed;
                    $this->closeWatch($watch, $reason, $now);
                    unset($watches[$cardId]);
                    $stale = true;
                }
            }
            if ($stale) {
                $this->em->flush();
            }

            [$wanted, $unread] = $enabled ? $this->wantedWaits($project, $cards) : [[], []];
            $countChanged = false;
            $nextNumber = null;
            $rewritten = [];
            foreach (array_keys($ids) as $cardId) {
                $card = $cards[$cardId] ?? null;
                $cause = $this->cause($card, $enabled);
                // No pass of the engine has read the card yet, so its open waits stay as they are.
                if (isset($unread[$cardId]) && InboxCardWaitEndReason::Resolved === $cause) {
                    continue;
                }
                $want = $wanted[$cardId] ?? [];
                $watch = $watches[$cardId] ?? null;
                if (null === $watch) {
                    if (null === $card || [] === $want) {
                        continue;
                    }
                    $nextNumber ??= $this->inboxItems->nextNumber($project);
                    $watch = $this->open($project, $card, $nextNumber++, $now);
                    $countChanged = true;
                }

                $ended = [];
                if ($this->apply($watch, $want, $cause, $now, $ended)) {
                    $countChanged = true;
                }
                if (null !== $card && $this->rewrite($watch, $card, $ended, $now)) {
                    $rewritten[] = $watch->item;
                }
            }

            $this->em->flush();
            foreach ($rewritten as $item) {
                $this->searchIndexer->index($item);
            }

            return [$countChanged, [] !== $rewritten];
        });

        if ($countChanged) {
            $this->inboxChanged->changed($project);
        } elseif ($pageChanged) {
            $this->inboxChanged->pageChanged($project);
        }
    }

    /**
     * @param list<string>|null $cardIds
     *
     * @return array<string, Uuid> card id => the same id as a Uuid
     */
    private function cardIds(Project $project, ?array $cardIds): array
    {
        $cardIds ??= [
            ...$this->inboxCardWatches->findOpenCardIds($project),
            ...$this->markers->findCardIdsWithInboxEntry($project->requireId()),
        ];

        $ids = [];
        foreach ($cardIds as $cardId) {
            $uuid = Uuid::fromString($cardId);
            $ids[$uuid->toRfc4122()] = $uuid;
        }

        return $ids;
    }

    /**
     * @param array<string, Card> $cards
     *
     * @return array{array<string, array<string, WantedCardWait>>, array<string, true>} the waits by card id and wait key, and the ids of the open cards that no pass of the engine has read
     */
    private function wantedWaits(Project $project, array $cards): array
    {
        $candidates = [];
        $unread = [];
        foreach ($cards as $cardId => $card) {
            if ($card->column->terminal) {
                continue;
            }
            $entries = $this->markers->readInbox($card->id ?? throw new \LogicException('A stored card has an id.'));
            if (null === $entries) {
                $unread[$cardId] = true;
                continue;
            }
            foreach ($entries as $entry) {
                if (null !== $entry->subject) {
                    $candidates[] = [$cardId, WantedCardWait::fromMarker($entry)];
                }
            }
        }
        if ([] === $candidates) {
            return [[], $unread];
        }

        $candidates = $this->withRunText($candidates);
        $dismissed = [];
        foreach ($this->inboxCardWatches->findDismissedForCards($project, array_values(array_map(Uuid::fromString(...), array_unique(array_column($candidates, 0))))) as $watch) {
            foreach ($watch->waits as $wait) {
                if (InboxCardWaitEndReason::Dismissed === $wait->endReason) {
                    $dismissed[(string) $watch->cardId][$wait->key()] = true;
                }
            }
        }

        $wanted = [];
        foreach ($candidates as [$cardId, $wait]) {
            $key = $wait->key();
            if (!isset($dismissed[$cardId][$key])) {
                $wanted[$cardId][$key] = $wait;
            }
        }

        return [$wanted, $unread];
    }

    /**
     * A wait of a run shows the kind of worker and the headline of its output, so the stored label can carry them.
     *
     * @param list<array{string, WantedCardWait}> $candidates
     *
     * @return list<array{string, WantedCardWait}>
     */
    private function withRunText(array $candidates): array
    {
        $runIds = [];
        foreach ($candidates as [, $wait]) {
            if (MarkerSubjectType::WorkerRun === $wait->subject->type) {
                $runIds[] = $wait->subject->ids['run'];
            }
        }
        $runs = [];
        foreach ($this->workerRuns->findByIds(array_values(array_unique($runIds))) as $run) {
            $runs[($run->id ?? throw new \LogicException('A stored run has an id.'))->toRfc4122()] = $run;
        }

        return array_map(static function (array $candidate) use ($runs): array {
            [$cardId, $wait] = $candidate;
            $run = MarkerSubjectType::WorkerRun === $wait->subject->type ? $runs[$wait->subject->ids['run']] ?? null : null;

            return [$cardId, $run instanceof WorkerRun ? $wait->withParams([...$wait->params, ...RunOutputText::params($run->workKind, $run->output)]) : $wait];
        }, $candidates);
    }

    /** Why a wait of this card that is no longer wanted ends. */
    private function cause(?Card $card, bool $enabled): InboxCardWaitEndReason
    {
        return match (true) {
            null === $card => InboxCardWaitEndReason::CardDeleted,
            $card->column->terminal => InboxCardWaitEndReason::CardFinished,
            !$enabled => InboxCardWaitEndReason::SwitchedOff,
            default => InboxCardWaitEndReason::Resolved,
        };
    }

    private function open(Project $project, Card $card, int $number, \DateTimeImmutable $now): InboxCardWatch
    {
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $title = self::title($card);
        $item = new InboxItem(
            project: $project,
            number: $number,
            kind: InboxItemKind::Wait,
            title: $title,
            blocking: true,
            origin: InboxAskOrigin::Loupe,
            createdAt: $now,
            searchLanguage: $project->searchLanguage,
        );
        $item->cards->add(new InboxItemCard($item, $card, $now));
        $this->em->persist($item);

        $ask = new InboxAsk(project: $project, sessionId: null, bridgeId: null, createdAt: $now);
        $ask->items->add(new InboxAskItem($ask, $item, $now));
        $this->em->persist($ask);
        // The Doctrine transport commits the message with the ask.
        $this->bus->dispatch(new NotifyInboxOwner(($ask->id ?? throw new \LogicException('A persisted ask has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Ask));

        $watch = new InboxCardWatch($item, $cardId, $card->number, $now);
        $this->em->persist($watch);

        return $watch;
    }

    /**
     * Ends the waits no longer wanted and starts the new ones. It closes the
     * item when no wait is left, and returns whether it did.
     *
     * @param array<string, WantedCardWait> $want
     * @param list<InboxCardWait>           $ended receives the waits this call ended
     */
    private function apply(InboxCardWatch $watch, array $want, InboxCardWaitEndReason $cause, \DateTimeImmutable $now, array &$ended): bool
    {
        $open = [];
        foreach ($watch->waits as $wait) {
            if (null === $wait->endedAt) {
                $open[$wait->key()] = $wait;
            }
        }

        $obsolete = InboxCardWaitEndReason::Resolved !== $cause;
        foreach ($open as $key => $wait) {
            if (!isset($want[$key])) {
                $wait->endedAt = $now;
                $wait->endReason = $cause;
                $ended[] = $wait;
                unset($open[$key]);
            }
        }

        foreach ($want as $key => $wanted) {
            if (isset($open[$key])) {
                $open[$key]->label = $wanted->label;
                $open[$key]->params = $wanted->params;
                continue;
            }
            $open[$key] = InboxCardWait::forSubject($watch, $wanted->markerKey, $wanted->label, $wanted->params, $wanted->subject, $wanted->since);
            $watch->waits->add($open[$key]);
            if (MarkerSubjectType::Document === $wanted->subject->type) {
                $document = $this->documents->find($wanted->subject->ids['document']);
                if (null !== $document) {
                    $this->link($watch->item, $document, $now);
                }
            }
        }

        if ([] !== $open) {
            return false;
        }

        $state = $obsolete ? InboxItemState::Cancelled : InboxItemState::Resolved;
        $this->closer->close($watch->item, $state, InboxCloser::Loupe, self::closeCause($cause), null, $now);
        $watch->closedAt = $now;

        return true;
    }

    /** The wait that decides how its item closes names the cause. */
    private static function closeCause(InboxCardWaitEndReason $reason): InboxCloseCause
    {
        return match ($reason) {
            InboxCardWaitEndReason::Resolved => InboxCloseCause::CauseEnded,
            InboxCardWaitEndReason::CardFinished => InboxCloseCause::CardFinished,
            InboxCardWaitEndReason::CardDeleted => InboxCloseCause::CardDeleted,
            InboxCardWaitEndReason::SwitchedOff => InboxCloseCause::SwitchedOff,
            InboxCardWaitEndReason::Dismissed => throw new \LogicException('A dismissed wait belongs to an item that is already closed.'),
        };
    }

    /** Ends the open waits of a watch whose item is already closed. */
    private function closeWatch(InboxCardWatch $watch, InboxCardWaitEndReason $reason, \DateTimeImmutable $now): void
    {
        foreach ($watch->waits as $wait) {
            if (null === $wait->endedAt) {
                $wait->endedAt = $now;
                $wait->endReason = $reason;
            }
        }
        $watch->closedAt = $now;
    }

    private function link(InboxItem $item, Document $document, \DateTimeImmutable $now): void
    {
        foreach ($item->documents as $link) {
            if ($link->document === $document) {
                return;
            }
        }
        $item->documents->add(new InboxItemDocument($item, $document, $now));
    }

    /**
     * Writes the kind, the title and the body from the card and the open waits, or from the waits this reconcile ended
     * when the item closed, and returns whether any changed. A closed item keeps its kind.
     *
     * @param list<InboxCardWait> $ended
     */
    private function rewrite(InboxCardWatch $watch, Card $card, array $ended, \DateTimeImmutable $now): bool
    {
        $open = null !== $watch->closedAt
            ? $ended
            : array_filter($watch->waits->toArray(), static fn (InboxCardWait $wait): bool => null === $wait->endedAt);
        usort($open, static fn (InboxCardWait $a, InboxCardWait $b): int => [$a->startedAt, $a->key()] <=> [$b->startedAt, $b->key()]);
        $title = $this->waitText->title($open, $card->title, InboxItem::MAX_TITLE_LENGTH);
        $body = $this->waitText->body($open);

        $item = $watch->item;
        $kind = null !== $watch->closedAt ? $item->kind : $this->waitText->kind($open);
        if ($item->title === $title && $item->body === $body && $item->kind === $kind) {
            return false;
        }
        $item->kind = $kind;
        $item->title = $title;
        $item->body = $body;
        $item->updatedAt = $now;

        return true;
    }

    private static function title(Card $card): string
    {
        return mb_substr($card->title, 0, InboxItem::MAX_TITLE_LENGTH);
    }

    /** Whole seconds, like the stored columns, so the wait order reads the same before and after a reload. */
    private static function now(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return $now->setTime((int) $now->format('G'), (int) $now->format('i'), (int) $now->format('s'));
    }
}
