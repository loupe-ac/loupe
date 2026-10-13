<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Contract\StoredMarker;
use Symfony\Component\Uid\Uuid;

/** Holds what a card had stored and remembers what the marker pass wrote. */
final class RecordingMarkerStore implements CardMarkerStore
{
    /** @var list<StoredMarker>|null null until the pass writes */
    public ?array $written = null;

    public ?\DateTimeImmutable $dueAt = null;

    /** @param list<StoredMarker> $stored */
    public function __construct(
        private readonly array $stored = [],
    ) {
    }

    #[\Override]
    public function read(Uuid $cardId): array
    {
        return $this->stored;
    }

    #[\Override]
    public function write(Uuid $projectId, Uuid $cardId, array $markers, ?\DateTimeImmutable $dueAt): void
    {
        $this->written = $markers;
        $this->dueAt = $dueAt;
    }
}
