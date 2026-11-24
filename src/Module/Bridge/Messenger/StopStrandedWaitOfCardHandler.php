<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\WorkerWaitEnder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/** Runs on the queue, because the stop takes the bridge lock and a card change holds the project lock. A resume request takes them in the other order. */
#[AsMessageHandler]
final readonly class StopStrandedWaitOfCardHandler
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private WorkerWaitEnder $ender,
    ) {
    }

    public function __invoke(StopStrandedWaitOfCard $message): void
    {
        $stranded = $this->workerWaits->findStrandedOfCard(Uuid::fromString($message->cardId));
        if (null !== $stranded) {
            $this->ender->stopStranded($stranded, $message->reason);
        }
    }
}
