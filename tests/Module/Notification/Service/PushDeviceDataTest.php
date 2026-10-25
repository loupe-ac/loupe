<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\PushDeviceAccountPurger;
use App\Module\Notification\Service\PushDeviceExporter;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\Notification\NotificationScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PushDeviceDataTest extends KernelTestCase
{
    use NotificationScenario;

    public function test_the_purger_deletes_the_rows_of_the_user_and_no_other(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'purge-user@example.com');
        $other = $this->makeUser($container, 'purge-other@example.com');
        $this->makeDevice($container, $user, 'https://web.push.apple.com/ONE', 'one');
        $this->makeDevice($container, $user, 'https://web.push.apple.com/TWO', 'two');
        $kept = $this->makeDevice($container, $other, 'https://web.push.apple.com/KEPT', 'kept');
        $keptId = $kept->id;

        $container->get(PushDeviceAccountPurger::class)->purge($user, new AccountDeletionCleanup());

        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        $repository = $container->get(PushDeviceRepository::class);
        self::assertSame(0, $repository->count(['owner' => $user->id]));
        self::assertNotNull($em->find(PushDevice::class, $keptId));
    }

    public function test_the_purger_runs_after_the_project_purger(): void
    {
        $container = static::getContainer();

        self::assertGreaterThan(
            $container->get(ProjectAccountPurger::class)->deletionOrder(),
            $container->get(PushDeviceAccountPurger::class)->deletionOrder(),
        );
    }

    public function test_the_exporter_holds_the_label_and_the_times_and_no_credential(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'export-user@example.com');
        $other = $this->makeUser($container, 'export-other@example.com');
        $device = $this->makeDevice($container, $user);
        $device->lastDeliveredAt = new \DateTimeImmutable('2026-03-01T10:00:00+00:00');
        $device->stoppedAt = new \DateTimeImmutable('2026-04-01T10:00:00+00:00');
        $this->makeDevice($container, $other, 'https://web.push.apple.com/THEIRS', 'theirs');

        $exporter = $container->get(PushDeviceExporter::class);
        $rows = iterator_to_array($exporter->export($user), false);

        self::assertSame('push_devices.json', $exporter->filename());
        self::assertCount(1, $rows);
        self::assertSame(['label', 'createdAt', 'lastDeliveredAt', 'stoppedAt'], array_keys($rows[0]));
        self::assertSame('Chrome on macOS', $rows[0]['label']);
        self::assertSame('2026-03-01T10:00:00+00:00', $rows[0]['lastDeliveredAt']);
        self::assertSame('2026-04-01T10:00:00+00:00', $rows[0]['stoppedAt']);
        $encoded = json_encode($rows, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($device->endpoint, $encoded);
        self::assertStringNotContainsString($device->authToken, $encoded);
        self::assertStringNotContainsString($device->publicKey, $encoded);
        self::assertStringNotContainsString($device->deviceTokenHash, $encoded);
    }
}
