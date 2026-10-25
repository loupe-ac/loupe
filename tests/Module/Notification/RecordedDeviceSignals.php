<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\UserTopicBuilder;
use App\Module\Notification\Service\DeviceChangedPublisher;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/** A DeviceChangedPublisher wired to a hub that records what the live publisher sends. */
final class RecordedDeviceSignals
{
    private const string APP_URL = 'https://loupe.example';

    public DeviceChangedPublisher $publisher;

    private readonly LiveUpdatePublisher $live;

    /** @var list<Update> */
    private array $updates = [];

    public function __construct()
    {
        $this->live = new LiveUpdatePublisher(
            new RequestStack(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new NullLogger(),
            fn (): MockHub => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                function (Update $update): string {
                    $this->updates[] = $update;

                    return 'id';
                },
            ),
        );
        $this->publisher = new DeviceChangedPublisher(new UserTopicBuilder(self::APP_URL), $this->live);
    }

    /** Nothing is sent before the publisher flushes, as after a response. */
    public function assertNothingSentYet(): void
    {
        Assert::assertSame([], $this->updates);
    }

    /** Flushes the queue the way the end of a request does, then checks what the hub got. */
    public function assertSignalled(?Uuid $userId, int $times = 1): void
    {
        $this->live->publish();

        $topic = new UserTopicBuilder(self::APP_URL)->forNotifications($userId ?? throw new \LogicException('The user has no id.'));
        Assert::assertCount($times, $this->updates);
        foreach ($this->updates as $update) {
            Assert::assertSame([$topic], $update->getTopics());
            Assert::assertTrue($update->isPrivate());
            Assert::assertSame('{"type":"notifications.devices_changed","origin":null}', $update->getData());
        }
    }

    public function assertNothingSignalled(): void
    {
        $this->live->publish();

        Assert::assertSame([], $this->updates);
    }
}
