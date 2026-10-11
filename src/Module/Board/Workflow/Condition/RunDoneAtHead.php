<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\WorkAtHeadFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/** A work request of the given kind ended done with the current head of the pull request the rule acts on. */
final readonly class RunDoneAtHead implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.done_at_head';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('kind', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [WorkAtHeadFacts::class, EngineFact::PullRequest];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(WorkAtHeadFacts::class)->doneAtHead($facts, ParameterValue::string($params, 'kind'));
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_done_at_head' : 'workflow.waiting.run_done_at_head');
    }
}
