<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Project\Service\ProjectsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The row of a project shows its open cards, so a card change moves its count. */
#[AsEventListener]
final readonly class PublishProjectsChangedOnCardChanged
{
    public function __construct(
        private ProjectsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        $this->publisher->projectChanged($event->projectId);
    }
}
