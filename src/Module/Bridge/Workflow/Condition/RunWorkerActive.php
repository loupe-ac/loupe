<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A worker run of the card is open, of the given work kind, or of any kind when no kind is given.
 * With `origin: rule`, a run that a person started does not count.
 */
final readonly class RunWorkerActive implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.worker_active';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('kind', ParameterType::String, required: false),
            new Parameter('origin', ParameterType::String, required: false, choices: [ActiveKindEvidence::ORIGIN_RULE]),
        ];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [WorkerRunFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $kind = ParameterValue::optionalString($params, 'kind');
        $runs = $facts->get(WorkerRunFacts::class);
        $active = ActiveKindEvidence::isRuleOnly($params) ? $runs->activeRuleKinds : $runs->activeKinds;

        return null === $kind ? [] !== $active : \in_array($kind, $active, true);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_worker_active' : 'workflow.waiting.run_worker_active');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $runs = $facts->get(WorkerRunFacts::class);

        return ActiveKindEvidence::of(ActiveKindEvidence::isRuleOnly($params) ? $runs->ruleSince : $runs->since, ParameterValue::optionalString($params, 'kind'));
    }
}
