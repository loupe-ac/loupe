<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Controller\Dev;

use App\Module\Notification\Controller\Dev\PreviewPushUnavailableController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

final class PreviewPushUnavailableControllerTest extends KernelTestCase
{
    public function test_the_preview_route_exists_in_dev_alone(): void
    {
        $class = new \ReflectionClass(PreviewPushUnavailableController::class);

        $when = $class->getAttributes(When::class);
        self::assertCount(1, $when);
        self::assertSame('dev', $when[0]->newInstance()->env);

        $route = $class->getAttributes(Route::class)[0]->newInstance();
        self::assertIsString($route->path);
        self::assertStringStartsWith('/dev/', $route->path);

        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        self::assertNull($router->getRouteCollection()->get('dev_notification_preview_push_unavailable'));
    }
}
