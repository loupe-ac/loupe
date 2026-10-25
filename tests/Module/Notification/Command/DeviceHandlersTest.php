<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Command;

use App\Exception\DomainErrors;
use App\Module\Notification\Command\ForgetPushDeviceCommand;
use App\Module\Notification\Command\ForgetPushDeviceHandler;
use App\Module\Notification\Command\ListPushDevicesCommand;
use App\Module\Notification\Command\ListPushDevicesHandler;
use App\Module\Notification\Command\RemovePushDeviceCommand;
use App\Module\Notification\Command\RemovePushDeviceHandler;
use App\Module\Notification\Command\SendTestPushCommand;
use App\Module\Notification\Command\SendTestPushHandler;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Messenger\DeliverWebPush;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\PushAvailability;
use App\Module\Notification\Service\PushUnavailableReason;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Module\Notification\RecordedDeviceSignals;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\AuditActorProviderInterface;

final class DeviceHandlersTest extends KernelTestCase
{
    use NotificationScenario;

    /** @var list<object> */
    private array $dispatched = [];

    public function test_the_owner_removes_a_device(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'remove-owner@example.com');
        $device = $this->makeDevice($container, $user);
        $audit = $this->audit();
        $signals = new RecordedDeviceSignals();

        $this->remover($audit, $signals)(new RemovePushDeviceCommand($user, self::idOf($device)));

        self::assertNull($container->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT));
        $audit->record('notification.device_removed');
        $signals->assertSignalled($user->id);
    }

    public function test_a_device_of_another_user_cannot_be_removed_and_reads_as_missing(): void
    {
        $container = static::getContainer();
        $other = $this->makeUser($container, 'remove-other@example.com');
        $user = $this->makeUser($container, 'remove-stranger@example.com');
        $device = $this->makeDevice($container, $other);

        $signals = new RecordedDeviceSignals();

        try {
            $this->remover($this->audit(), $signals)(new RemovePushDeviceCommand($user, self::idOf($device)));
            self::fail('Expected a 404.');
        } catch (NotFoundHttpException) {
        }

        self::assertNotNull($container->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT));
        $signals->assertNothingSignalled();
    }

    public function test_a_test_push_queues_one_declarative_message_for_the_device(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'test-push@example.com');
        $device = $this->makeDevice($container, $user);

        $this->tester($this->audit())(new SendTestPushCommand($user, self::idOf($device)));

        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0];
        self::assertInstanceOf(DeliverWebPush::class, $message);
        self::assertSame(self::idOf($device)->toRfc4122(), $message->deviceId);
        $payload = json_decode($message->payload, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(8030, $payload['web_push']);
        self::assertTrue($payload['mutable']);
        self::assertSame('notification.push.test.title', $payload['notification']['title']);
        self::assertSame('notification.push.test.body', $payload['notification']['body']);
        self::assertSame('https://loupe.test/account/notifications', $payload['notification']['navigate']);
        self::assertSame('test', $payload['notification']['tag']);
        self::assertSame('https://loupe.test/icons/push-192.png', $payload['notification']['icon']);
        self::assertSame(['url' => 'https://loupe.test/account/notifications'], $payload['notification']['data']);
        self::assertArrayNotHasKey('openTags', $payload['notification']['data'], 'The service worker closes every shown push whose tag is not in openTags, so a test push must not close a live ask.');
    }

    public function test_a_test_push_is_refused_when_push_is_unavailable(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'test-push-off@example.com');
        $device = $this->makeDevice($container, $user);

        $errors = $this->errorsOf(fn () => $this->tester($this->audit(), self::availability(false))(new SendTestPushCommand($user, self::idOf($device))));

        self::assertSame(['device' => 'notification.device.error.unavailable'], $errors);
        self::assertSame([], $this->dispatched);
    }

    public function test_a_test_push_is_refused_for_a_stopped_device(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'test-push-stopped@example.com');
        $device = $this->makeDevice($container, $user);
        $device->stoppedAt = new \DateTimeImmutable();

        $errors = $this->errorsOf(fn () => $this->tester($this->audit())(new SendTestPushCommand($user, self::idOf($device))));

        self::assertSame(['device' => 'notification.device.error.stopped'], $errors);
        self::assertSame([], $this->dispatched);
    }

    public function test_a_test_push_to_a_device_of_another_user_reads_as_missing(): void
    {
        $container = static::getContainer();
        $other = $this->makeUser($container, 'test-push-other@example.com');
        $user = $this->makeUser($container, 'test-push-stranger@example.com');
        $device = $this->makeDevice($container, $other);

        try {
            $this->tester($this->audit())(new SendTestPushCommand($user, self::idOf($device)));
            self::fail('Expected a 404.');
        } catch (NotFoundHttpException) {
        }

        self::assertSame([], $this->dispatched);
    }

    public function test_forgetting_removes_only_the_row_of_that_user_and_that_cookie(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'forget-user@example.com');
        $other = $this->makeUser($container, 'forget-other@example.com');
        $mine = $this->makeDevice($container, $user, 'https://web.push.apple.com/MINE', 'cookie-hash');
        $theirs = $this->makeDevice($container, $other, 'https://web.push.apple.com/THEIRS', 'cookie-hash');
        $elsewhere = $this->makeDevice($container, $user, 'https://web.push.apple.com/ELSE', 'another-hash');
        $mineId = $mine->id;
        $signals = new RecordedDeviceSignals();

        new ForgetPushDeviceHandler(
            $container->get(PushDeviceRepository::class),
            $container->get(EntityManagerInterface::class),
            $this->audit()->auditor,
            $signals->publisher,
        )(new ForgetPushDeviceCommand($user, 'cookie-hash'));

        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(PushDevice::class, $mineId));
        self::assertNotNull($em->find(PushDevice::class, $theirs->id));
        self::assertNotNull($em->find(PushDevice::class, $elsewhere->id));
        $signals->assertSignalled($user->id);
    }

    public function test_forgetting_a_browser_with_no_row_signals_nothing(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'forget-none@example.com');
        $signals = new RecordedDeviceSignals();

        new ForgetPushDeviceHandler(
            $container->get(PushDeviceRepository::class),
            $container->get(EntityManagerInterface::class),
            $this->audit()->auditor,
            $signals->publisher,
        )(new ForgetPushDeviceCommand($user, 'no-such-hash'));

        $signals->assertNothingSignalled();
    }

    public function test_the_list_holds_the_devices_of_the_user_oldest_first_and_marks_the_current_one(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'list-user@example.com');
        $other = $this->makeUser($container, 'list-other@example.com');
        $first = $this->makeDevice($container, $user, 'https://web.push.apple.com/ONE', 'hash-one');
        $second = $this->makeDevice($container, $user, 'https://web.push.apple.com/TWO', 'hash-two');
        $this->makeDevice($container, $other, 'https://web.push.apple.com/THREE', 'hash-two');

        $handler = new ListPushDevicesHandler($container->get(PushDeviceRepository::class), self::availability());
        $view = $handler(new ListPushDevicesCommand($user, 'hash-two'));

        self::assertSame([$first, $second], $view->devices);
        self::assertSame((string) $second->id, $view->currentDeviceId);
        self::assertTrue($view->available);
        self::assertNull($handler(new ListPushDevicesCommand($user, null))->currentDeviceId);
        self::assertNull($handler(new ListPushDevicesCommand($user, 'unknown'))->currentDeviceId);
    }

    public function test_the_list_reports_why_push_is_unavailable(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'list-off@example.com');

        $view = new ListPushDevicesHandler($container->get(PushDeviceRepository::class), self::availability(false))(new ListPushDevicesCommand($user, null));

        self::assertFalse($view->available);
        self::assertSame(PushUnavailableReason::FlagOff, $view->unavailableReason);
    }

    private function audit(): RecordingAuditor
    {
        return new RecordingAuditor(static::getContainer()->get(AuditActorProviderInterface::class));
    }

    private function remover(RecordingAuditor $audit, RecordedDeviceSignals $signals): RemovePushDeviceHandler
    {
        $container = static::getContainer();

        return new RemovePushDeviceHandler($container->get(PushDeviceRepository::class), $container->get(EntityManagerInterface::class), $audit->auditor, $signals->publisher);
    }

    private function tester(RecordingAuditor $audit, ?PushAvailability $availability = null): SendTestPushHandler
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/account/notifications');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SendTestPushHandler(
            static::getContainer()->get(PushDeviceRepository::class),
            $availability ?? self::availability(),
            $bus,
            $urls,
            $translator,
            $audit->auditor,
            'https://loupe.test/',
        );
    }

    /** @return array<string, string> */
    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (DomainErrors $e) {
            return $e->errors;
        }

        self::fail('Expected DomainErrors.');
    }
}
