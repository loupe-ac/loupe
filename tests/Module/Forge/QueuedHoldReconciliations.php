<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Messenger\ReconcileRepositoryHold;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Reads and delivers the hold reconciliations that a test left on the jobs queue. */
trait QueuedHoldReconciliations
{
    /** @return list<string> the project id of each reconciliation queued so far, delivered or not */
    private function queuedReconciliations(): array
    {
        return array_values(array_map(
            static fn (ReconcileRepositoryHold $message): string => $message->projectId,
            array_filter(
                array_map(static fn ($envelope): object => $envelope->getMessage(), $this->jobs()->getSent()),
                static fn (object $message): bool => $message instanceof ReconcileRepositoryHold,
            ),
        ));
    }

    /**
     * Handles each waiting reconciliation as the worker does. A failed one stays
     * on the queue, as a retry leaves it.
     *
     * @return list<\Throwable> the failures
     */
    private function deliverReconciliations(): array
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $failures = [];
        foreach ($this->pendingReconciliations() as $envelope) {
            try {
                $bus->dispatch($envelope->with(new ReceivedStamp('jobs')));
                $this->jobs()->ack($envelope);
            } catch (HandlerFailedException $e) {
                $failures[] = $e->getPrevious() ?? $e;
            }
        }

        return $failures;
    }

    /**
     * The transport fetches one envelope at a time, so this reads the queue
     * as the sent envelopes that nobody acknowledged.
     *
     * @return list<Envelope>
     */
    private function pendingReconciliations(): array
    {
        $acknowledged = array_map(static fn (Envelope $envelope): mixed => $envelope->last(TransportMessageIdStamp::class)?->getId(), $this->jobs()->getAcknowledged());

        return array_values(array_filter(
            $this->jobs()->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof ReconcileRepositoryHold
                && !\in_array($envelope->last(TransportMessageIdStamp::class)?->getId(), $acknowledged, true),
        ));
    }

    private function jobs(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
