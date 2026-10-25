<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Messenger\DeliverWebPush;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\PushContent;
use App\Module\Notification\Repository\NotificationPreferenceRepository;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\NotificationPreferences;
use App\Module\Notification\Service\Notifier;
use App\Module\Notification\Service\PresenceStore;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class NotifierTest extends TestCase
{
    use NotificationScenario;

    private MockClock $clock;
    private PresenceStore $presence;
    private User $user;

    private RecordingMessageBus $bus;

    /** @var list<PushDevice> */
    private array $devices = [];

    /** @var list<NotificationPreference> */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-25 12:00:00');
        $this->presence = new PresenceStore(new ArrayAdapter(), $this->clock);
        $this->user = new User(fullName: 'Riley', email: 'riley@example.com', password: 'x');
        new \ReflectionProperty(User::class, 'id')->setValue($this->user, Uuid::v7());
        $this->bus = new RecordingMessageBus();
        $this->stored = [];
        $this->devices = [$this->device('https://web.push.apple.com/ONE')];
    }

    public function test_it_queues_one_message_per_live_device(): void
    {
        $this->devices = [$this->device('https://web.push.apple.com/ONE'), $this->device('https://fcm.googleapis.com/TWO')];
        $content = $this->content();

        $this->notifier()->notify($this->user, NotificationEvent::BlockingInboxItem, $content);

        $sent = $this->sent();
        self::assertCount(2, $sent);
        foreach ($sent as $message) {
            self::assertSame($content->toJson(), $message->payload);
            self::assertSame(604800, $message->ttl);
        }
        self::assertEquals(
            array_map(self::idOf(...), $this->devices),
            array_map(static fn (DeliverWebPush $message): Uuid => Uuid::fromString($message->deviceId), $sent),
        );
    }

    public function test_a_blocking_item_goes_with_high_urgency_and_another_with_normal(): void
    {
        $this->stored[] = new NotificationPreference($this->user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true);

        $this->notifier()->notify($this->user, NotificationEvent::BlockingInboxItem, $this->content());
        $this->notifier()->notify($this->user, NotificationEvent::OtherInboxItem, $this->content());

        $sent = $this->sent();
        self::assertCount(2, $sent);
        self::assertSame('high', $sent[0]->urgency);
        self::assertSame('normal', $sent[1]->urgency);
    }

    public function test_it_sends_nothing_when_push_is_unavailable(): void
    {
        $this->notifier(available: false)->notify($this->user, NotificationEvent::BlockingInboxItem, $this->content());

        self::assertSame([], $this->sent());
    }

    public function test_it_sends_nothing_when_the_owner_turned_the_event_off(): void
    {
        $this->stored[] = new NotificationPreference($this->user, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false);

        $this->notifier()->notify($this->user, NotificationEvent::BlockingInboxItem, $this->content());

        self::assertSame([], $this->sent());
    }

    public function test_the_default_of_an_event_that_is_off_sends_nothing(): void
    {
        $this->notifier()->notify($this->user, NotificationEvent::OtherInboxItem, $this->content());

        self::assertSame([], $this->sent());
    }

    public function test_it_sends_nothing_while_the_owner_is_present(): void
    {
        $this->presence->record($this->user);

        $this->notifier()->notify($this->user, NotificationEvent::BlockingInboxItem, $this->content());

        self::assertSame([], $this->sent());
    }

    public function test_it_sends_once_the_owner_has_been_away_for_91_seconds(): void
    {
        $this->presence->record($this->user);
        $this->clock->sleep(91);

        $this->notifier()->notify($this->user, NotificationEvent::BlockingInboxItem, $this->content());

        self::assertCount(1, $this->sent());
    }

    public function test_it_sends_nothing_to_a_user_with_no_live_device(): void
    {
        $this->devices = [];

        $this->notifier()->notify($this->user, NotificationEvent::BlockingInboxItem, $this->content());

        self::assertSame([], $this->sent());
    }

    public function test_it_asks_the_repository_for_live_devices_only(): void
    {
        $repository = $this->createMock(PushDeviceRepository::class);
        $repository->expects(self::once())->method('findActiveByOwner')->with($this->user)->willReturn([]);

        $this->notifier(devices: $repository)->notify($this->user, NotificationEvent::BlockingInboxItem, $this->content());
    }

    private function notifier(bool $available = true, ?PushDeviceRepository $devices = null): Notifier
    {
        if (null === $devices) {
            $devices = $this->createStub(PushDeviceRepository::class);
            $devices->method('findActiveByOwner')->willReturnCallback(fn (): array => $this->devices);
        }
        $preferences = $this->createStub(NotificationPreferenceRepository::class);
        $preferences->method('findOneByCell')->willReturnCallback(function (User $owner, NotificationEvent $event, NotificationChannel $channel): ?NotificationPreference {
            foreach ($this->stored as $preference) {
                if ($preference->event === $event && $preference->channel === $channel) {
                    return $preference;
                }
            }

            return null;
        });

        return new Notifier(self::availability($available), new NotificationPreferences($preferences), $this->presence, $devices, $this->bus, new NullLogger());
    }

    /** @return list<DeliverWebPush> */
    private function sent(): array
    {
        return $this->bus->dispatched(DeliverWebPush::class);
    }

    private function device(string $endpoint): PushDevice
    {
        $keys = self::subscriptionKeys();
        $device = new PushDevice($this->user, $endpoint, $keys['publicKey'], $keys['authToken'], 'aes128gcm', 'Chrome on macOS', 'hash');
        new \ReflectionProperty(PushDevice::class, 'id')->setValue($device, Uuid::v7());

        return $device;
    }

    private function content(): PushContent
    {
        return new PushContent('Acme', 'Which column?', 'https://app.example/inbox?ask=a1', 'ask-a1', ['ask-a1']);
    }
}
