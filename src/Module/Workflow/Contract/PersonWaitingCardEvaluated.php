<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The engine evaluated a card that a person waits on. Messenger copies no stamp to a new dispatch, so this event carries that fact. */
final readonly class PersonWaitingCardEvaluated
{
    public function __construct(
        public string $cardId,
    ) {
    }
}
