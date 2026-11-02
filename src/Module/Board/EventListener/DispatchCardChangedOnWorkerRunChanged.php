<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Service\CardChangeAnnouncer;
use App\Module\Bridge\Event\WorkerRunChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/** A run that opens or closes moves its card between Working and another state. */
#[AsEventListener]
final readonly class DispatchCardChangedOnWorkerRunChanged
{
    public function __construct(
        private CardChangeAnnouncer $cardChanges,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $this->cardChanges->updated($event->projectId, array_map(Uuid::fromString(...), $event->cardIds));
    }
}
