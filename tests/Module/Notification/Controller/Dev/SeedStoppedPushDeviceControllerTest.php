<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Controller\Dev;

use App\Module\Notification\Command\SeedStoppedPushDeviceCommand;
use App\Module\Notification\Command\SeedStoppedPushDeviceHandler;
use App\Module\Notification\Controller\Dev\SeedStoppedPushDeviceController;
use App\Tests\Module\Notification\NotificationScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Routing\Attribute\Route;

final class SeedStoppedPushDeviceControllerTest extends KernelTestCase
{
    use NotificationScenario;

    public function test_the_seed_route_exists_in_dev_alone(): void
    {
        $class = new \ReflectionClass(SeedStoppedPushDeviceController::class);

        $when = $class->getAttributes(When::class);
        self::assertCount(1, $when);
        self::assertSame('dev', $when[0]->newInstance()->env);
        $route = $class->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('/dev/seed/stopped-push-device', $route->path);
        self::assertNull(static::getContainer()->get('router')->getRouteCollection()->get('app_dev_seed_stopped_push_device'));
    }

    public function test_the_handler_seeds_a_stopped_device_and_a_dismissed_one(): void
    {
        $user = $this->makeUser(static::getContainer(), 'seed-stopped@example.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $handler = new SeedStoppedPushDeviceHandler($em);
        $at = new \DateTimeImmutable('2026-10-20 08:00:00');

        $open = $handler(new SeedStoppedPushDeviceCommand($user, 'Safari on iPad', $at, false));
        $dismissed = $handler(new SeedStoppedPushDeviceCommand($user, 'Chrome on Mac', $at, true));

        self::assertSame('Safari on iPad', $open->label);
        self::assertEquals($at, $open->stoppedAt);
        self::assertNull($open->warningDismissedAt);
        self::assertNotNull($dismissed->warningDismissedAt);
        self::assertNotSame($open->endpoint, $dismissed->endpoint);
    }
}
