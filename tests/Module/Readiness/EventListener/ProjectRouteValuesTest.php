<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Readiness\EventListener\RequireProjectSetUpListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

final class ProjectRouteValuesTest extends KernelTestCase
{
    public function test_the_listener_finds_the_project_in_every_route_under_projects(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $id = (string) Uuid::v7();
        $walked = 0;
        $missing = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/projects/')) {
                continue;
            }

            $mapping = $route->getDefault('_route_mapping') ?? [];
            self::assertIsArray($mapping);
            $request = new Request();
            foreach ($route->compile()->getVariables() as $variable) {
                $attribute = $mapping[$variable] ?? $variable;
                $request->attributes->set(is_string($attribute) ? $attribute : $variable, $id);
            }

            ++$walked;
            if ($id !== RequireProjectSetUpListener::projectValue($request)) {
                $missing[] = $name.' '.$route->getPath();
            }
        }

        self::assertGreaterThan(100, $walked, 'The walk must visit the project routes.');
        self::assertSame([], $missing, 'A route under /projects/ with a third shape escapes the setup gate.');
    }
}
