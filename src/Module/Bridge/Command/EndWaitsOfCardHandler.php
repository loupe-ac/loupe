<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Messenger\StopStrandedWaitOfCard;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\WorkerWaitEnder;
use Symfony\Component\Messenger\MessageBusInterface;

/** A move, a finish or a hold of a card ends its open wait, and stops the parked run of a wait that its offline bridge could not resume. The owner made the change, so no confirmation is needed. */
final readonly class EndWaitsOfCardHandler
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private WorkerWaitEnder $ender,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(EndWaitsOfCardCommand $command): void
    {
        $wait = $this->workerWaits->findOpenOfCard($command->cardId);
        if (null !== $wait) {
            $this->ender->end($wait, $command->reason);
        }
        // The stop takes the bridge lock, and this handler runs under the project lock of the card change, so the queue runs it.
        if (null !== $this->workerWaits->findStrandedOfCard($command->cardId)) {
            $this->bus->dispatch(new StopStrandedWaitOfCard($command->cardId->toRfc4122(), $command->reason));
        }
    }
}
