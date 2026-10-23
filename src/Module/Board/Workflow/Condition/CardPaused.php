<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\PauseFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\NamesMarkerSubject;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardPaused implements Condition, MarkerEvidence, NamesMarkerSubject
{
    #[\Override]
    public static function key(): string
    {
        return 'card.paused';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [PauseFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(PauseFacts::class)->active;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_paused' : 'workflow.waiting.card_paused');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $pause = $facts->get(PauseFacts::class)->active;

        return null === $pause ? null : new Evidence(['%reason%' => $pause->reason], new MarkerSubject(MarkerSubjectType::CardPause, ['pause' => $pause->id->toRfc4122()]));
    }

    #[\Override]
    public function subjectType(): MarkerSubjectType
    {
        return MarkerSubjectType::CardPause;
    }
}
