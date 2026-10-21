<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\RunNeedsPersonFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\NamesMarkerSubject;
use Symfony\Component\Translation\TranslatableMessage;

/** The newest run of the card blocked, gave up or waits for a person, and the card did not move since it ended. */
final readonly class RunNeedsPerson implements Condition, MarkerEvidence, NamesMarkerSubject
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.needs_person';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [RunNeedsPersonFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(RunNeedsPersonFacts::class)->needsPerson();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_run_needs_person' : 'workflow.waiting.card_run_needs_person');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $runId = $facts->get(RunNeedsPersonFacts::class)->runId;

        return null === $runId ? null : new Evidence([], new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => $runId]));
    }

    #[\Override]
    public function subjectType(): MarkerSubjectType
    {
        return MarkerSubjectType::WorkerRun;
    }
}
