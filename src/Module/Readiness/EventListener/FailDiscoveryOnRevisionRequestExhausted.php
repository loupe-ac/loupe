<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Readiness\Messenger\RequestDiscoveryRevision;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/** The message that opens a revision ran out of retries. Without this, its run stays Revising and refuses each new start. */
#[AsEventListener]
final readonly class FailDiscoveryOnRevisionRequestExhausted
{
    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private FailDiscoveryRunHandler $failDiscoveryRun,
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof RequestDiscoveryRevision || $event->willRetry()) {
            return;
        }
        // A failed transaction closes the entity manager, and the worker resets it only after this event.
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
        }

        $run = $this->discoveryRuns->find($message->runId);
        if (null !== $run) {
            ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($run, StartDiscoveryHandler::REQUEST_FAILED, FailDiscoveryRunHandler::REVISE_RULE_ID));
        }
    }
}
