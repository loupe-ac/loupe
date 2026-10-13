<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Contract\CardsDueForMarkers;
use App\Module\Workflow\Contract\StoredMarker;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Keeps the markers in the card row. The engine flushes the row in its own transaction. */
#[AsAlias(CardMarkerStore::class)]
#[AsAlias(CardsDueForMarkers::class)]
final readonly class BoardCardMarkers implements CardMarkerStore, CardsDueForMarkers
{
    public function __construct(
        private CardRepository $cards,
        private EventDispatcherInterface $events,
    ) {
    }

    #[\Override]
    public function read(Uuid $cardId): array
    {
        $card = $this->cards->find($cardId);

        return null === $card ? [] : StoredCardMarkers::of($card)->markers;
    }

    #[\Override]
    public function write(Uuid $projectId, Uuid $cardId, array $markers, ?\DateTimeImmutable $dueAt): void
    {
        $card = $this->cards->find($cardId);
        if (null === $card) {
            return;
        }

        $entries = array_map(static fn (StoredMarker $marker): array => [
            'key' => $marker->key,
            'kind' => $marker->kind->value,
            'label' => $marker->label,
            'remedy' => $marker->remedy,
            'params' => $marker->params,
            'since' => $marker->since->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ], $markers);

        $changed = $entries !== ($card->markers ?? []);
        if ($changed || null === $card->markers) {
            $card->markers = $entries;
        }
        // Doctrine compares a date by identity, so an equal instant would write the row again.
        if ($dueAt != $card->markersDueAt) {
            $card->markersDueAt = $dueAt;
        }
        if ($changed) {
            $this->events->dispatch(new CardChanged($projectId, $cardId, CardChanged::UPDATED, false, markersOnly: true));
        }
    }

    #[\Override]
    public function findDueCardIds(\DateTimeImmutable $now, int $limit): array
    {
        return $this->cards->findIdsDueForMarkers($now, $limit);
    }
}
