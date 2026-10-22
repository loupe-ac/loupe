<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Service\DocumentVerdictEvents;
use App\Module\Review\Event\VerdictWithdrawn;
use App\Module\Workflow\Contract\Actor;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class WriteCardEventOnVerdictWithdrawn
{
    public function __construct(
        private DocumentVerdictEvents $events,
    ) {
    }

    public function __invoke(VerdictWithdrawn $event): void
    {
        $withdrawal = $event->withdrawal;
        $byRule = null !== $withdrawal->workflowRule;

        $this->events->record(
            $withdrawal->version->document,
            $withdrawal->workflowRule,
            $byRule ? Actor::System : Actor::Human,
            $byRule ? null : $withdrawal->reviewer,
            ['verdict' => $withdrawal->verdict->value, 'withdrawn' => $event->withdrawn->verdict->value],
            $withdrawal->submittedAt,
        );
    }
}
