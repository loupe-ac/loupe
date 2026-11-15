<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\LiveUpdatePublisher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final class LiveUpdatePublisherListenersTest extends KernelTestCase
{
    public function test_a_failed_message_publishes_after_its_own_failure_listeners_ran(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $publishers = array_values(array_filter(
            $dispatcher->getListeners(WorkerMessageFailedEvent::class),
            static fn (mixed $listener): bool => \is_array($listener) && $listener[0] instanceof LiveUpdatePublisher && 'publish' === $listener[1],
        ));

        self::assertCount(1, $publishers);
        self::assertLessThan(0, $dispatcher->getListenerPriority(WorkerMessageFailedEvent::class, $publishers[0]));
    }

    public function test_a_handled_message_still_publishes(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $publishers = array_filter(
            $dispatcher->getListeners(WorkerMessageHandledEvent::class),
            static fn (mixed $listener): bool => \is_array($listener) && $listener[0] instanceof LiveUpdatePublisher,
        );

        self::assertCount(1, $publishers);
    }
}
