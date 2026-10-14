<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\RetryNote;
use App\Module\Workflow\Contract\RetryNotes;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * A retry releases the latest pause of the card and re-arms its rule. The first request of that rule carries the retry,
 * and a newer pause ends the match.
 */
#[AsAlias(RetryNotes::class)]
final readonly class InboxRetryNotes implements RetryNotes
{
    public function __construct(
        private CardPauses $cardPauses,
        private InboxActionRepository $inboxActions,
    ) {
    }

    #[\Override]
    public function forRuleWork(Uuid $cardId, string $ruleId): ?RetryNote
    {
        $pause = $this->cardPauses->findLatest($cardId);
        if (null === $pause || null === $pause->releasedAt || $pause->ruleId !== $ruleId) {
            return null;
        }
        $action = $this->inboxActions->findUnsentRetry($pause->id);
        if (null === $action) {
            return null;
        }

        return new RetryNote(
            $action->id ?? throw new \LogicException('A stored action has an id.'),
            $action->item->id ?? throw new \LogicException('A stored item has an id.'),
            $action->item->number,
        );
    }
}
