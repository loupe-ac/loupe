<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\WorkerWaitFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use Symfony\Component\Translation\TranslatableMessage;

/** The card has an open wait of a worker. The evidence names the conditions that the worker waits for. */
final readonly class RunWorkerWaiting implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.worker_waiting';
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
        return null !== $facts->get(WorkerWaitFacts::class)->wait;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_worker_waiting' : 'workflow.waiting.run_worker_waiting');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $waits = $facts->get(WorkerWaitFacts::class);

        return null === $waits->wait ? null : new Evidence(['%conditions%' => $waits->conditionsText()]);
    }
}
