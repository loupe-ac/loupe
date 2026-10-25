<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox;

use App\Module\Inbox\Messenger\NotifyInboxOwner;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Reads the push requests that a test left on the jobs queue. */
trait QueuedPushes
{
    private function jobs(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<NotifyInboxOwner> */
    private function queuedPushes(): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->jobs()->getSent()),
            static fn (object $message): bool => $message instanceof NotifyInboxOwner,
        ));
    }
}
