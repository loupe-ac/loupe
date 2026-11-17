<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Contract\CardsDueForMarkers;
use App\Module\Workflow\Contract\MarkerWrite;
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
    public function readInbox(Uuid $cardId): ?array
    {
        $card = $this->cards->find($cardId);

        return null === $card?->inboxMarkers ? null : StoredCardMarkers::entries($card->inboxMarkers);
    }

    #[\Override]
    public function findCardIdsWithInboxEntry(Uuid $projectId): array
    {
        return $this->cards->findIdsWithInboxEntry($projectId);
    }

    #[\Override]
    public function write(Uuid $projectId, Uuid $cardId, array $markers, array $inbox, ?\DateTimeImmutable $dueAt): MarkerWrite
    {
        $card = $this->cards->find($cardId);
        if (null === $card) {
            return new MarkerWrite(false);
        }

        $entries = array_map(self::entry(...), $markers);
        $inboxEntries = array_map(self::entry(...), $inbox);

        $keys = [...self::changedKeys($card->markers ?? [], $entries), ...self::changedKeys($card->inboxMarkers ?? [], $inboxEntries)];
        $changed = $entries !== ($card->markers ?? []);
        if ($changed || null === $card->markers) {
            $card->markers = $entries;
        }
        // A null inbox set means the engine has not read the card yet, so the first set counts as a change even when it is empty.
        $inboxChanged = $inboxEntries !== $card->inboxMarkers;
        if ($inboxChanged) {
            $card->inboxMarkers = $inboxEntries;
        }
        // Doctrine compares a date by identity, so an equal instant would write the row again.
        if ($dueAt != $card->markersDueAt) {
            $card->markersDueAt = $dueAt;
        }
        if ($changed || $inboxChanged) {
            $this->events->dispatch(new CardChanged($projectId, $cardId, CardChanged::UPDATED, false, markersOnly: true));
        }

        return new MarkerWrite($changed || $inboxChanged, array_values(array_unique($keys)));
    }

    /**
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     *
     * @return list<string> the keys whose entries differ
     */
    private static function changedKeys(array $old, array $new): array
    {
        $group = static function (array $entries): array {
            $byKey = [];
            foreach ($entries as $entry) {
                $byKey[$entry['key']][] = $entry;
            }

            return $byKey;
        };
        $before = $group($old);
        $after = $group($new);

        return array_values(array_filter(
            array_keys($before + $after),
            static fn (string|int $key): bool => ($before[$key] ?? []) !== ($after[$key] ?? []),
        ));
    }

    /** @return array{key: string, kind: string, label: string, remedy: ?string, params: array<string, string|int>, since: string, link?: string, subject?: array{type: string, ids: array<string, string>}} */
    private static function entry(StoredMarker $marker): array
    {
        $entry = [
            'key' => $marker->key,
            'kind' => $marker->kind->value,
            'label' => $marker->label,
            'remedy' => $marker->remedy,
            'params' => $marker->params,
            'since' => $marker->since->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
        if (null !== $marker->link) {
            $entry['link'] = $marker->link->value;
        }
        if (null !== $marker->subject) {
            $entry['subject'] = $marker->subject->toArray();
        }

        return $entry;
    }

    #[\Override]
    public function findDueCardIds(\DateTimeImmutable $now, int $limit): array
    {
        return $this->cards->findIdsDueForMarkers($now, $limit);
    }
}
