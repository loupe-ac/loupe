<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * For a KernelTestCase that reads what the publisher sends to the hub. Call
 * captureLiveUpdates() after bootKernel() and before anything builds the hub.
 */
trait CapturesLiveUpdates
{
    /** @var list<Update> */
    private array $sentUpdates = [];

    private function captureLiveUpdates(): void
    {
        $hub = new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->sentUpdates[] = $update;

                return 'id';
            },
        );
        self::getContainer()->set('mercure.hub.default', $hub);
    }

    /**
     * Ends the request the way the kernel does, and answers the updates the hub
     * received since the last call.
     *
     * @return list<array{topic: string, data: array<string, mixed>}>
     */
    private function drainLiveUpdates(): array
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertNotNull(self::$kernel);
        $dispatcher->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        $sent = $this->sentUpdates;
        $this->sentUpdates = [];

        return array_map(static function (Update $update): array {
            self::assertTrue($update->isPrivate());
            $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return ['topic' => $update->getTopics()[0], 'data' => $data];
        }, $sent);
    }
}
