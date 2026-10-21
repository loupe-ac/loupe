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

    /** @var list<StoredMarker>|null null until the pass writes */
    public ?array $writtenInbox = null;

    public ?\DateTimeImmutable $dueAt = null;

    /**
     * @param list<StoredMarker>  $stored      the board set
     * @param ?list<StoredMarker> $storedInbox the inbox set, null for a card that no pass of this version read
     */
    public function __construct(
        private readonly array $stored = [],
        private readonly ?array $storedInbox = null,
    ) {
    }

    #[\Override]
    public function read(Uuid $cardId): array
    {
        return $this->stored;
    }

    #[\Override]
    public function readInbox(Uuid $cardId): ?array
    {
        return $this->storedInbox;
    }

    #[\Override]
    public function write(Uuid $projectId, Uuid $cardId, array $markers, array $inbox, ?\DateTimeImmutable $dueAt): void
    {
        $this->written = $markers;
        $this->writtenInbox = $inbox;
        $this->dueAt = $dueAt;
    }
}
