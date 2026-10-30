<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkTakers;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxBridgeOutage;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxBridgeOutageRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keeps one open notice per project while work has waited a set time with no
 * bridge online that could take it, and closes it when a bridge is back or the
 * waiting work ended. Running it twice changes nothing, so any trigger may ask again.
 *
 * The switches of the card waits do not gate this notice.
 */
final readonly class BridgeOutageReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkRequestRepository $workRequests,
        private WorkTakers $workTakers,
        private CardRepository $cards,
        private InboxBridgeOutageRepository $inboxBridgeOutages,
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxSearchIndexer $searchIndexer,
        private InboxChangedPublisher $inboxChanged,
        private InboxAvailability $inbox,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private MessageBusInterface $bus,

        #[Autowire(param: 'app.inbox.bridge_outage_after_minutes')]
        private int $afterMinutes,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public function reconcile(Project $project): void
    {
        $outcome = $this->em->wrapInTransaction(function () use ($project): ?bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $now = self::whole($this->clock->now());
            $enabled = $this->inbox->isEnabled();
            $outage = $this->inboxBridgeOutages->findOpenForProject($project);

            // Doctrine inserts before it updates, so an outage whose item closed
            // elsewhere must end in the database before a new one is inserted.
            if (null !== $outage && InboxItemState::Open !== $outage->item->state) {
                $outage->endedAt = $now;
                $outage = null;
                $this->em->flush();
            }

            $readings = $enabled ? $this->readings($project) : [];
            $withoutTaker = $this->withoutTaker($readings);
            if (null !== $outage && [] === $withoutTaker) {
                $this->close($outage, $enabled, $now);
                $this->em->flush();

                return true;
            }

            // The delay decides when the notice opens. The notice then lists every waiting card at once.
            $cards = $this->cardsOf($project, $withoutTaker);
            if ([] === $cards) {
                return null;
            }

            if (null === $outage) {
                $cutoff = $now->modify(\sprintf('-%d minutes', $this->afterMinutes));
                $due = array_filter($withoutTaker, static fn (array $row): bool => $row['reading']->noTakerSince <= $cutoff
                    && isset($cards[$row['request']->subjectId->toRfc4122()]));
                if ([] === $due) {
                    return null;
                }

                $outage = $this->open($project, $cards, self::earliest($due), $now);
                $this->em->flush();
                $this->searchIndexer->index($outage->item);

                return true;
            }

            if (!$this->extend($outage->item, $cards, $now)) {
                return null;
            }
            $this->em->flush();
            $this->searchIndexer->index($outage->item);

            return false;
        });

        if (true === $outcome) {
            $this->inboxChanged->changed($project);
        } elseif (false === $outcome) {
            $this->inboxChanged->pageChanged($project);
        }
    }

    /** @return array<string, array{request: WorkRequest, reading: WorkTakerReading}> keyed by the id of the request */
    private function readings(Project $project): array
    {
        $byId = [];
        foreach ($this->workRequests->findOpenOfCardsInProject($project) as $request) {
            $byId[($request->id ?? throw new \LogicException('A stored request has an id.'))->toRfc4122()] = $request;
        }

        $rows = [];
        foreach ($this->workTakers->forRequests($project, array_values($byId)) as $requestId => $reading) {
            $rows[$requestId] = ['request' => $byId[$requestId], 'reading' => $reading];
        }

        return $rows;
    }

    /**
     * @param array<string, array{request: WorkRequest, reading: WorkTakerReading}> $readings
     *
     * @return array<string, array{request: WorkRequest, reading: WorkTakerReading}>
     */
    private function withoutTaker(array $readings): array
    {
        return array_filter($readings, static fn (array $row): bool => \in_array($row['reading']->state, [WorkTakerState::None, WorkTakerState::NotCapable], true));
    }

    /**
     * @param array<string, array{request: WorkRequest, reading: WorkTakerReading}> $rows
     *
     * @return array<string, Card> card id => card, in card number order
     */
    private function cardsOf(Project $project, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['request']->subjectId->toRfc4122()] = $row['request']->subjectId;
        }

        $cards = [];
        foreach ($this->cards->findByIdsInProject($project, array_values($ids)) as $card) {
            $cards[($card->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122()] = $card;
        }
        uasort($cards, static fn (Card $a, Card $b): int => $a->number <=> $b->number);

        return $cards;
    }

    /**
     * @param array<string, array{request: WorkRequest, reading: WorkTakerReading}> $rows
     */
    private static function earliest(array $rows): \DateTimeImmutable
    {
        $earliest = null;
        foreach ($rows as $row) {
            if (null === $earliest || $row['reading']->noTakerSince < $earliest) {
                $earliest = $row['reading']->noTakerSince;
            }
        }

        return $earliest ?? throw new \LogicException('A notice opens for at least one waiting request.');
    }

    /** @param array<string, Card> $cards */
    private function open(Project $project, array $cards, \DateTimeImmutable $startedAt, \DateTimeImmutable $now): InboxBridgeOutage
    {
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: InboxItemKind::Notice,
            title: $this->translator->trans('inbox.bridge_outage.title', [], null, $this->locale),
            blocking: false,
            origin: InboxAskOrigin::Loupe,
            createdAt: $now,
            searchLanguage: $project->searchLanguage,
        );
        foreach ($cards as $card) {
            $item->cards->add(new InboxItemCard($item, $card, $now));
        }
        $item->body = $this->body($item);
        $outage = new InboxBridgeOutage($project, $item, $startedAt);
        $this->em->persist($item);
        $this->em->persist($outage);
        // The Doctrine transport commits the message with the notice.
        $this->bus->dispatch(new NotifyInboxOwner(($item->id ?? throw new \LogicException('A persisted item has an id.'))->toRfc4122(), NotifyInboxOwnerKind::Notice));

        return $outage;
    }

    /**
     * Links the cards the item does not hold yet and writes its body again.
     *
     * @param array<string, Card> $cards
     */
    private function extend(InboxItem $item, array $cards, \DateTimeImmutable $now): bool
    {
        $linked = [];
        foreach ($item->cards as $link) {
            $linked[($link->card->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122()] = true;
        }

        $added = false;
        foreach ($cards as $cardId => $card) {
            if (!isset($linked[$cardId])) {
                $item->cards->add(new InboxItemCard($item, $card, $now));
                $added = true;
            }
        }
        if (!$added) {
            return false;
        }

        $item->body = $this->body($item);
        $item->updatedAt = $now;

        return true;
    }

    private function body(InboxItem $item): string
    {
        $cards = array_map(static fn (InboxItemCard $link): Card => $link->card, $item->cards->toArray());
        usort($cards, static fn (Card $a, Card $b): int => $a->number <=> $b->number);

        $lines = array_map(
            fn (Card $card): string => '- '.$this->translator->trans('inbox.bridge_outage.body.card', ['%number%' => $card->number, '%title%' => $card->title], null, $this->locale),
            $cards,
        );

        return $this->translator->trans('inbox.bridge_outage.body.intro', [], null, $this->locale)
            ."\n\n".implode("\n", $lines)
            ."\n\n".$this->translator->trans('inbox.bridge_outage.body.remedy', [], null, $this->locale);
    }

    /**
     * A bridge is back when a card of the notice still has live work. The notice is obsolete
     * when none has, because the waiting work ended with no bridge back.
     */
    private function close(InboxBridgeOutage $outage, bool $enabled, \DateTimeImmutable $now): void
    {
        $item = $outage->item;
        $cardIds = array_values(array_map(static fn (InboxItemCard $link): string => ($link->card->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122(), $item->cards->toArray()));
        $recovered = $enabled && [] !== $this->workRequests->findLiveRowsForCards($cardIds);

        $note = $recovered ? $this->translator->trans('inbox.bridge_outage.closed', [
            '%start%' => self::stamp($outage->startedAt),
            '%end%' => self::stamp($now),
            '%count%' => \count($cardIds),
        ], null, $this->locale) : null;

        $this->closer->close($item, $recovered ? InboxItemState::Resolved : InboxItemState::Cancelled, InboxCloser::Loupe, InboxCloseCause::FactEnded, $note, $now);
        $outage->endedAt = $now;
    }

    private static function stamp(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
    }

    /** Whole seconds, like the stored columns. */
    private static function whole(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTime((int) $moment->format('G'), (int) $moment->format('i'), (int) $moment->format('s'));
    }
}
