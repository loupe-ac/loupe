<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\RunStoppedFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use Symfony\Component\Translation\TranslatableMessage;

/** The latest run of the card ended with a warning, and the card did not move since it ended. */
final readonly class RunStopped implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.stopped';
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
        return [RunStoppedFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(RunStoppedFacts::class)->stopped();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_run_stopped' : 'workflow.waiting.card_run_stopped');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $stopped = $facts->get(RunStoppedFacts::class)->stopped();

        return null === $stopped ? null : new Evidence(['%state%' => $stopped->state->value]);
    }
}
