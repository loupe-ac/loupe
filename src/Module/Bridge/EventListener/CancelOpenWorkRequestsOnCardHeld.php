<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Event\CardHeld;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The engine skips a held card, so it cannot cancel a request that the hold made stale. The release asks for the work again.
 * A claimed request keeps its worker, which a stop ends.
 */
#[AsEventListener]
final readonly class CancelOpenWorkRequestsOnCardHeld
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private WithdrawWorkRequestHandler $withdraw,
    ) {
    }

    public function __invoke(CardHeld $event): void
    {
        foreach ($this->workRequests->findOpenForCard($event->cardId) as $request) {
            ($this->withdraw)(new WithdrawWorkRequestCommand(
                $request->id ?? throw new \LogicException('A stored request has an id.'),
                WorkRequestState::Cancelled,
                onlyIfOpen: true,
            ));
        }
    }
}
