<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardMoved;
use App\Module\Project\Event\ProjectCardsFinishedChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** A move into or out of a terminal column changes the finished cards. A move between two columns of one kind changes none. */
#[AsEventListener]
final readonly class AnnounceFinishedCardsOnCardMoved
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        if ($event->move->fromColumn->terminal === $event->card->column->terminal) {
            return;
        }
        $this->events->dispatch(new ProjectCardsFinishedChanged((string) $event->card->project->requireId()));
    }
}
