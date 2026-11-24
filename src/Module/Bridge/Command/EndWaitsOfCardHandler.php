<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\WorkerWaitEnder;

/** A move, a finish or a hold of a card ends its open wait. The owner made the change, so no confirmation is needed. */
final readonly class EndWaitsOfCardHandler
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private WorkerWaitEnder $ender,
    ) {
    }

    public function __invoke(EndWaitsOfCardCommand $command): void
    {
        $wait = $this->workerWaits->findOpenOfCard($command->cardId);
        if (null !== $wait) {
            $this->ender->end($wait, $command->reason);
        }
    }
}
