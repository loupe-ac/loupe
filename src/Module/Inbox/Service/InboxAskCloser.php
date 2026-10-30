<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Messenger\ResumeAskingSession;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Outbox\OutboxWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Closes each open ask that a closed item leaves with no open blocking item,
 * writes one inbox.ask_closed row for an ask a bridge can resume, and queues
 * the resume of its session. When one answer closes several asks of sessions
 * on the same card, only the session that took the item first resumes. The
 * card of each closed ask evaluates again, because its fix rules wait on it.
 *
 * InboxItemCloser calls it, so the caller holds the project lock and the
 * transaction. It does not flush and throws no domain error, because it runs
 * inside a card move or a column delete.
 */
final readonly class InboxAskCloser
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
        private WorkerRunRepository $workerRuns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private OutboxWriter $outbox,
        private MessageBusInterface $bus,
        private CardEvaluations $evaluations,
        private LoggerInterface $logger,
    ) {
    }

    /** @param InboxEventType::ACTOR_* $actor */
    public function closeAsksHolding(InboxItem $item, string $actor, \DateTimeImmutable $now): void
    {
        // Only a blocking item holds an ask back, so no other close can end one.
        if (!$item->blocking) {
            return;
        }

        $closing = [];
        foreach ($this->inboxAsks->findOpenHolding($item) as $ask) {
            // Closed earlier in this unit of work and not flushed yet.
            if (null !== $ask->closedAt || $this->holdsOpenBlockingItem($ask)) {
                continue;
            }

            $ask->closedAt = $now;
            if (null !== $ask->bridgeId) {
                $ask->card = $this->cardOfSession($ask);
                $this->writeEvent($ask, $actor);
                $closing[] = $ask;
            }
        }

        $winners = $this->winnersOf($item, $closing);
        foreach ($closing as $ask) {
            $winner = $winners[$this->groupOf($ask)];
            if ($winner === $ask) {
                $this->resumeSession($ask, $now);
            } else {
                $this->skipResume($ask, $winner, $item, $now);
            }
        }

        $cardIds = array_values(array_filter(array_map(static fn (InboxAsk $ask): ?Uuid => $ask->card?->id, $closing)));
        if ([] !== $cardIds) {
            $this->evaluations->forCards($cardIds);
        }
    }

    /**
     * One ask for each card: the one that took the item first. An ask with no card stands alone.
     *
     * @param list<InboxAsk> $closing
     *
     * @return array<string, InboxAsk> keyed by groupOf()
     */
    private function winnersOf(InboxItem $item, array $closing): array
    {
        $byCard = [];
        foreach ($closing as $ask) {
            $key = $this->groupOf($ask);
            $held = $byCard[$key] ?? null;
            if (null === $held || $this->tookFirst($ask, $held, $item)) {
                $byCard[$key] = $ask;
            }
        }

        return $byCard;
    }

    private function groupOf(InboxAsk $ask): string
    {
        return null === $ask->card ? 'ask:'.$ask->id : 'card:'.$ask->card->id;
    }

    /** The earliest link to the item wins, and the lower link id breaks a tie. */
    private function tookFirst(InboxAsk $candidate, InboxAsk $held, InboxItem $item): bool
    {
        $mine = $this->linkTo($candidate, $item);
        $theirs = $this->linkTo($held, $item);

        return [$mine->addedAt, (string) $mine->id] < [$theirs->addedAt, (string) $theirs->id];
    }

    private function linkTo(InboxAsk $ask, InboxItem $item): InboxAskItem
    {
        return array_find($ask->items->toArray(), static fn (InboxAskItem $link): bool => $link->item === $item) ?? throw new \LogicException('A closing ask holds the item that closed it.');
    }

    private function skipResume(InboxAsk $ask, InboxAsk $winner, InboxItem $item, \DateTimeImmutable $now): void
    {
        $ask->resumeSkipped = true;
        $this->logger->info('inbox.ask_resume_skipped', [
            'projectId' => (string) $ask->project->id,
            'askId' => (string) $ask->id,
            'sessionId' => (string) $ask->sessionId,
            'winningAskId' => (string) $winner->id,
            'itemId' => (string) $item->id,
            'cardId' => (string) $ask->card?->id,
        ]);
        if (null !== $ask->card) {
            $this->cardEvents->record($ask->card, CardEventKind::ResumeSkipped, Actor::System, null, ['number' => $item->number, 'sessionId' => (string) $ask->sessionId], $now);
        }
    }

    /**
     * Open as stored and open as loaded: a batch closes several items before
     * the flush, and the rows of the items it already closed still read open.
     */
    private function holdsOpenBlockingItem(InboxAsk $ask): bool
    {
        return array_any($this->inboxItems->findOpenBlockingIdsOf($ask), fn ($id) => InboxItemState::Open === $this->inboxItems->find($id)?->state);
    }

    private function cardOfSession(InboxAsk $ask): ?Card
    {
        if (null === $ask->sessionId) {
            return null;
        }
        $cardId = $this->workerRuns->findFirstOfSession($ask->project, $ask->sessionId)?->cardId();

        return null === $cardId ? null : $this->cards->findOneByIdAndProjectId((string) $cardId, (string) $ask->project->id);
    }

    /** The Doctrine transport commits the message with the close, and the queue sends the resume after it. */
    private function resumeSession(InboxAsk $ask, \DateTimeImmutable $closedAt): void
    {
        if (null === $ask->sessionId || null === $ask->bridgeId) {
            return;
        }

        $this->bus->dispatch(
            new ResumeAskingSession((string) $ask->project->id, (string) $ask->bridgeId, (string) $ask->sessionId, $closedAt, personWaiting: true),
            [new TransportNamesStamp([CardEvaluations::INTERACTIVE_TRANSPORT])],
        );
    }

    /** @param InboxEventType::ACTOR_* $actor */
    private function writeEvent(InboxAsk $ask, string $actor): void
    {
        // Every key is a contract with the bridge. Ids only: text a person or an
        // agent wrote must never reach an agent through a directive.
        $this->outbox->write($ask->project, InboxEventType::ASK_CLOSED, [
            'type' => InboxEventType::ASK_CLOSED,
            'projectId' => (string) $ask->project->id,
            'subject' => ['type' => 'inbox-ask', 'id' => (string) $ask->id],
            'sessionId' => (string) $ask->sessionId,
            'bridgeId' => (string) $ask->bridgeId,
            'cardId' => null === $ask->card ? null : (string) $ask->card->id,
            'cardNumber' => $ask->card?->number,
            'actor' => $actor,
        ]);
    }
}
