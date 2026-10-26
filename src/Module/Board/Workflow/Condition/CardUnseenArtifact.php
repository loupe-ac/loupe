<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\ArtifactReachFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** True when an artifact linked to the card or to its parent is not in the seen list of the card. */
final readonly class CardUnseenArtifact implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.review.unseen_artifact';
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
        return [ArtifactReachFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return [] !== $facts->get(ArtifactReachFacts::class)->unseenArtifactIds;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_review_unseen_artifact' : 'workflow.waiting.card_review_unseen_artifact');
    }
}
