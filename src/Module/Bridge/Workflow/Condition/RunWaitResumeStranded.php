<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\WorkerWaitFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** A wait ended, its worker run is parked, and the bridge that holds the session is offline, so nothing can resume it yet. */
final readonly class RunWaitResumeStranded implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.wait_resume_stranded';
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
        return [WorkerWaitFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(WorkerWaitFacts::class)->strandedOffline();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_wait_resume_stranded' : 'workflow.waiting.run_wait_resume_stranded');
    }
}
