<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Command;

use App\Module\Notification\Command\DismissPushDeviceWarningCommand;
use App\Module\Notification\Command\DismissPushDeviceWarningHandler;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Module\Notification\RecordedDeviceSignals;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditActorProviderInterface;

final class DismissPushDeviceWarningHandlerTest extends KernelTestCase
{
    use NotificationScenario;

    private const string NOW = '2026-10-25 09:00:00';

    private RecordingAuditor $audit;

    private RecordedDeviceSignals $signals;

    public function test_it_dismisses_the_warning_of_a_stopped_device_and_signals_the_pages(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'dismiss-owner@example.com');
        $device = $this->stoppedDevice($user);

        $this->handler()(new DismissPushDeviceWarningCommand($user, self::idOf($device)));

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->reload($device)->warningDismissedAt);
        self::assertNotNull($this->reload($device)->stoppedAt, 'the device stays stopped');
        $this->signals->assertSignalled($user->id);
        $record = $this->audit->record('notification.device_warning_dismissed');
        self::assertSame(['deviceId' => (string) $device->id], $record->context);
    }

    public function test_a_device_that_still_works_is_left_alone(): void
    {
        $user = $this->makeUser(static::getContainer(), 'dismiss-working@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);

        $this->handler()(new DismissPushDeviceWarningCommand($user, self::idOf($device)));

        self::assertNull($this->reload($device)->warningDismissedAt);
        $this->signals->assertNothingSignalled();
        self::assertSame([], $this->audit->records('notification.device_warning_dismissed'));
    }

    public function test_a_second_dismiss_changes_nothing_and_signals_nothing(): void
    {
        $user = $this->makeUser(static::getContainer(), 'dismiss-twice@example.com');
        $device = $this->stoppedDevice($user);
        $first = new \DateTimeImmutable('2026-01-02 03:04:05');
        $device->warningDismissedAt = $first;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->handler()(new DismissPushDeviceWarningCommand($user, self::idOf($device)));

        self::assertEquals($first, $this->reload($device)->warningDismissedAt);
        $this->signals->assertNothingSignalled();
        self::assertSame([], $this->audit->records('notification.device_warning_dismissed'));
    }

    public function test_a_device_of_another_user_reads_as_missing_and_stays_untouched(): void
    {
        $container = static::getContainer();
        $owner = $this->makeUser($container, 'dismiss-theirs@example.com');
        $thief = $this->makeUser($container, 'dismiss-thief@example.com');
        $device = $this->stoppedDevice($owner);

        try {
            $this->handler()(new DismissPushDeviceWarningCommand($thief, self::idOf($device)));
            self::fail('Expected a 404.');
        } catch (NotFoundHttpException) {
        }

        self::assertNull($this->reload($device)->warningDismissedAt);
        $this->signals->assertNothingSignalled();
    }

    public function test_an_unknown_device_reads_as_missing(): void
    {
        $user = $this->makeUser(static::getContainer(), 'dismiss-unknown@example.com');

        $this->expectException(NotFoundHttpException::class);

        $this->handler()(new DismissPushDeviceWarningCommand($user, Uuid::v7()));
    }

    public function test_a_device_that_was_set_up_again_after_the_read_is_not_dismissed(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'dismiss-race@example.com');
        $device = $this->stoppedDevice($user);
        // A re-subscribe lands between the read and the write: the row works again.
        $container->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE push_devices SET stopped_at = NULL WHERE id = ?', [(string) $device->id]);

        $this->handler()(new DismissPushDeviceWarningCommand($user, self::idOf($device)));

        $row = $container->get(EntityManagerInterface::class)->getConnection()->fetchAssociative('SELECT stopped_at, warning_dismissed_at FROM push_devices WHERE id = ?', [(string) $device->id]);
        self::assertSame(['stopped_at' => null, 'warning_dismissed_at' => null], $row);
        $this->signals->assertNothingSignalled();
    }

    private function stoppedDevice(\App\Module\Account\Entity\User $user): PushDevice
    {
        $device = $this->makeDevice(static::getContainer(), $user);
        $device->stoppedAt = new \DateTimeImmutable('2026-10-20 08:00:00');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $device;
    }

    private function reload(PushDevice $device): PushDevice
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(PushDevice::class, $device->id);
        self::assertInstanceOf(PushDevice::class, $fresh);

        return $fresh;
    }

    private function handler(): DismissPushDeviceWarningHandler
    {
        $container = static::getContainer();
        $this->audit = new RecordingAuditor($container->get(AuditActorProviderInterface::class));
        $this->signals = new RecordedDeviceSignals();

        return new DismissPushDeviceWarningHandler(
            $container->get(PushDeviceRepository::class),
            new MockClock(self::NOW),
            $this->audit->auditor,
            $this->signals->publisher,
        );
    }
}
