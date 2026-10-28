<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;

/** The engine stamps the due time of a retry on its rule states, so it gives this fact itself. */
final readonly class ScheduledRetryFactProvider implements FactProvider
{
    public function __construct(
        private WorkflowRuleStateRepository $workflowRuleStates,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return ScheduledRetryFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new ScheduledRetryFacts($this->workflowRuleStates->findRetryDueTimes($card->id));
    }

    /** A refusal writes the due time, so a fingerprint of it would let a rule that reads it skip its own backoff. */
    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof ScheduledRetryFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return null;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
