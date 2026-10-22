<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Service\DocumentVerdictEvents;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Workflow\Contract\Actor;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A workflow rule leaves a row in the history of each linked card. The cards of a person's verdict already show the move that it causes. */
#[AsEventListener]
final readonly class WriteCardEventOnReviewSubmitted
{
    public function __construct(
        private DocumentVerdictEvents $events,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        $review = $event->review;
        if (null === $review->workflowRule) {
            return;
        }

        $this->events->record(
            $review->version->document,
            $review->workflowRule,
            Actor::System,
            null,
            ['verdict' => $review->verdict->value],
            $review->submittedAt,
        );
    }
}
