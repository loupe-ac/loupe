<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Repository\CardRepository;
use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Workflow\Contract\PersonWaitingCardEvaluated;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The card a person approved moved, so its ask closes next. The reconcile skips the jobs backlog. */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnPersonWaitingCardEvaluated
{
    public function __construct(
        private CardRepository $cards,
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(PersonWaitingCardEvaluated $event): void
    {
        $card = $this->cards->find($event->cardId);
        if (null === $card) {
            return;
        }

        $this->trigger->forPersonWaiting($card->project->id ?? throw new \LogicException('Project has no id.'), $event->cardId);
    }
}
