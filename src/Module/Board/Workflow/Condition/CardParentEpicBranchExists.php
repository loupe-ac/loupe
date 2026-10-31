<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\EpicBranchExistsFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardParentEpicBranchExists implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.parent.epic_branch.exists';
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
        return [EpicBranchExistsFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(EpicBranchExistsFacts::class)->exists;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_parent_epic_branch_exists' : 'workflow.waiting.card_parent_epic_branch_exists');
    }
}
