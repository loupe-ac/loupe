<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/** At least `min` approvals cover the head commit. Only people approve a pull request, so `by: person` gives the same answer. */
final readonly class PullRequestApprovalCoversHead implements Condition
{
    private const string BY_PERSON = 'person';

    #[\Override]
    public static function key(): string
    {
        return 'pr.approval_covers_head';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.forge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('min', ParameterType::Int),
            new Parameter('by', ParameterType::String, required: false, choices: [self::BY_PERSON]),
        ];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [EngineFact::PullRequest];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $pullRequest = $facts->pullRequest;

        if (null === $pullRequest) {
            return false;
        }

        return $pullRequest->approvalsCoveringHead >= ParameterValue::int($params, 'min');
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_approval_covers_head' : 'workflow.waiting.pr_approval_covers_head', ['%min%' => (string) ParameterValue::int($params, 'min')]);
    }
}
