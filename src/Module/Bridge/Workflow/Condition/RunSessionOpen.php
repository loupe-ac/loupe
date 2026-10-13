<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\SessionFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use Symfony\Component\Translation\TranslatableMessage;

/** A person runs an interactive session on the card. */
final readonly class RunSessionOpen implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.session_open';
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
        return [SessionFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(SessionFacts::class)->openSince;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_run_session_open' : 'workflow.waiting.card_run_session_open');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $session = $facts->get(SessionFacts::class);
        if (null === $session->openSince) {
            return null;
        }

        return new Evidence($session->openSince, null === $session->kind ? [] : ['%kind%' => $session->kind]);
    }
}
