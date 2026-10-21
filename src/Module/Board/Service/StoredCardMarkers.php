<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerLink;
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
        return new self(array_map(
            static fn (array $entry): StoredMarker => new StoredMarker(
                $entry['key'],
                MarkerKind::from($entry['kind']),
                $entry['label'],
                $entry['remedy'],
                $entry['params'],
                new \DateTimeImmutable($entry['since']),
                null === ($entry['link'] ?? null) ? null : MarkerLink::from($entry['link']),
            ),
            $card->markers ?? [],
        ));
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

    /** The marker a reader sees first: the state, or the first chip when the card has only chips. */
    public function primary(): ?StoredMarker
    {
        return $this->state() ?? $this->markers[0] ?? null;
    }

    /** @return list<StoredMarker> the other markers that ask the user to act, or that are chips; stored order */
    public function alsoApplies(): array
    {
        $primary = $this->primary();

        return array_values(array_filter($this->markers, static fn (StoredMarker $marker): bool => $marker !== $primary
            && \in_array($marker->kind, [MarkerKind::Stuck, MarkerKind::NeedsYou, MarkerKind::Chip], true)));
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
