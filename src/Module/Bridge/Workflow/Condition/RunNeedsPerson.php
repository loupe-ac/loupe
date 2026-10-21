<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\RunNeedsPersonFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The newest run of the card blocked, gave up or waits for a person, and the card did not move since it ended. */
final readonly class RunNeedsPerson implements Condition
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
}
