<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow\Condition;

use App\Module\Inbox\Workflow\WaitingAskFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** A session stopped on a run of the card to wait for the answer to a blocking question, whether or not the question names the card. */
final readonly class CardRunWaitsOnAnswer implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.waits_on_answer';
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
        return [WaitingAskFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(WaitingAskFacts::class)->waits();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_run_waits_on_answer' : 'workflow.waiting.card_run_waits_on_answer');
    }
}
