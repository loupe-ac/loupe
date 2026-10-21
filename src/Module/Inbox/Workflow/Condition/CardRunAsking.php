<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow\Condition;

use App\Module\Inbox\Workflow\RunAskingFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/** The newest run of the card waits on its own question, or that question closed less than 10 minutes ago. */
final readonly class CardRunAsking implements Condition, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.asking';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.inbox';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [RunAskingFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(RunAskingFacts::class)->asking($facts->now);
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $end = $facts->get(RunAskingFacts::class)->graceEndsAt;

        return null !== $end && $end > $facts->now ? $end : null;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_run_asking' : 'workflow.waiting.card_run_asking');
    }
}
