<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Event\WorkerRunChanged;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Removes the ask that a run waits on, because the resume after the answer
 * failed. The change reaches the inbox, which then opens the stop item.
 */
final readonly class ClearRunAskHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ClearRunAskCommand $command): void
    {
        $run = $command->run;
        if (null === $run->waitsOnAskId) {
            return;
        }

        $askId = $run->waitsOnAskId;
        $run->waitsOnAskId = null;
        $this->em->flush();

        $this->logger->info('bridge.run_ask_cleared', ['runId' => (string) $run->id, 'askId' => (string) $askId]);
        foreach (WorkerRunChanged::ofRuns([$run]) as $event) {
            $this->events->dispatch($event);
        }
    }
}
