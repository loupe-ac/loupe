<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\CardsDueForMarkers;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use Psr\Clock\ClockInterface;

/** Queues the cards with a rule due and the cards with markers that are due or not stored yet. Answers how many evaluations it queued. */
final readonly class EvaluateDueWorkflowCardsHandler
{
    public const int BATCH = 500;

    public function __construct(
        private WorkflowRuleStateRepository $workflowRuleStates,
        private CardsDueForMarkers $markerDue,
        private EvaluationTrigger $trigger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EvaluateDueWorkflowCardsCommand $command): int
    {
        $now = $this->clock->now();
        $cardIds = array_values(array_unique([
            ...$this->workflowRuleStates->findDueCardIds($now, self::BATCH),
            ...$this->markerDue->findDueCardIds($now, self::BATCH),
        ]));
        $this->trigger->forCards($cardIds);

        return \count($cardIds);
    }
}
