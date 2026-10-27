<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\RecordPullRequestReadyCommand;
use App\Module\Board\Command\RecordPullRequestReadyHandler;
use App\Module\Forge\Event\PullRequestStateChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Forge clears readySince when the pull request merges, so the card event is
 * the only record of when it turned ready. Only the false to true edge writes.
 */
#[AsEventListener]
final readonly class RecordReadyToMergeOnPullRequestStateChanged
{
    public function __construct(
        private RecordPullRequestReadyHandler $record,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        $readyAt = $event->pullRequest->readySince;
        if ($event->previous->readyToMerge || !$event->current->readyToMerge || null === $readyAt) {
            return;
        }

        ($this->record)(new RecordPullRequestReadyCommand($event->pullRequest, $readyAt));
    }
}
