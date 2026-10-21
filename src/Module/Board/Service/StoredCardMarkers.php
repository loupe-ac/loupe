<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerLink;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\StoredMarker;

/** The markers the engine stored on one card, in display order. A read runs no query. */
final readonly class StoredCardMarkers
{
    /** @param list<StoredMarker> $markers */
    public function __construct(
        public array $markers,
    ) {
    }

    public static function of(Card $card): self
    {
        return new self(self::entries($card->markers ?? []));
    }

    /**
     * @param list<array{key: string, kind: string, label: string, remedy: ?string, params: array<string, string|int>, since: string, link?: ?string, subject?: array{type: string, ids: array<string, string>}}> $entries
     *
     * @return list<StoredMarker>
     */
    public static function entries(array $entries): array
    {
        return array_map(
            static fn (array $entry): StoredMarker => new StoredMarker(
                $entry['key'],
                MarkerKind::from($entry['kind']),
                $entry['label'],
                $entry['remedy'],
                $entry['params'],
                new \DateTimeImmutable($entry['since']),
                null === ($entry['link'] ?? null) ? null : MarkerLink::from($entry['link']),
                null === ($entry['subject'] ?? null) ? null : MarkerSubject::fromArray($entry['subject']),
            ),
            $entries,
        );
    }

    public function isEmpty(): bool
    {
        return [] === $this->markers;
    }

    /** The marker that gives the card its mark: the first one that is not a chip. */
    public function state(): ?StoredMarker
    {
        return array_find($this->markers, static fn (StoredMarker $marker): bool => MarkerKind::Chip !== $marker->kind);
    }

    /** @return list<StoredMarker> every marker except {@see state()}, chips included */
    public function others(): array
    {
        $state = $this->state();

        return array_values(array_filter($this->markers, static fn (StoredMarker $marker): bool => $marker !== $state));
    }

    /** A short stable hash of what each marker shows. */
    public function digest(): string
    {
        return substr(sha1(json_encode(array_map(static function (StoredMarker $marker): array {
            $params = $marker->params;
            ksort($params);

            return [$marker->key, $marker->kind->value, $marker->label, $marker->remedy, $params, $marker->since->format('U'), $marker->link?->value];
        }, $this->markers), \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
