<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

use App\Module\Bridge\Command\ResumeWaitingRunCommand;
use App\Module\Bridge\Command\ResumeWaitingRunHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ResumeWaitingSessionHandler
{
    public function __construct(
        private ResumeWaitingRunHandler $resume,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ResumeWaitingSession $message): void
    {
        // Handled inline in the middle of a workflow pass, the request would take the bridge lock under the project lock. The queue runs it afterwards.
        if ($this->em->getUnitOfWork()->hasPendingInsertions()) {
            $this->bus->dispatch(new Envelope($message, [new TransportNamesStamp(['jobs'])]));

            return;
        }

        ($this->resume)(new ResumeWaitingRunCommand(Uuid::fromString($message->waitId)));
    }
}
