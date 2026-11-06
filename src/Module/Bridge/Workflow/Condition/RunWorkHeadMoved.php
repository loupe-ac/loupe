<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/** An open or claimed work request of the given kind named a head that is not the current head of the pull request the rule acts on. */
final readonly class RunWorkHeadMoved implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.work_head_moved';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('kind', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [WorkRequestFacts::class, EngineFact::PullRequest, EngineFact::PullRequestHead];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $pullRequest = $facts->pullRequest;
        $head = $pullRequest?->headSha;
        if (null === $head) {
            return false;
        }
        $kind = ParameterValue::string($params, 'kind');

        return array_any(
            $facts->get(WorkRequestFacts::class)->heads[$kind] ?? [],
            static fn (array $named): bool => (null === $named['number'] || $named['number'] === $pullRequest->number) && 0 !== strcasecmp($named['headSha'], $head),
        );
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_work_head_moved' : 'workflow.waiting.run_work_head_moved');
    }
}
