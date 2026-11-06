<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Routing\ControllerAttributeProbe;
use App\Tests\Routing\Fixture\GoodController;
use App\Tests\Routing\Fixture\MissingClassController;
use App\Tests\Routing\Fixture\MissingModelController;
use App\Tests\Routing\Fixture\MissingParentController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class ControllerAttributeProbeTest extends KernelTestCase
{
    public function test_it_reports_each_bad_shape_and_accepts_the_good_ones(): void
    {
        $result = $this->probe([
            'good' => GoodController::class,
            'good_service' => 'good.service::__invoke',
            'missing_class' => MissingClassController::class,
            'missing_parent' => MissingParentController::class.'::__invoke',
            'missing_model' => MissingModelController::class,
            'unknown_service' => 'unknown.service::__invoke',
        ], ['good.service' => GoodController::class]);

        $failures = implode("\n", $result['failures']);
        self::assertCount(4, $result['failures'], $failures);
        self::assertStringContainsString('missing_class', $result['failures'][0]);
        self::assertStringContainsString('Absent\NotInstalled', $failures);
        self::assertStringContainsString('missing_parent', $failures);
        self::assertStringContainsString('ExtendsMissingParent', $failures);
        self::assertStringContainsString('missing_model', $failures);
        self::assertStringContainsString('MissingModel', $failures);
        self::assertStringContainsString('unknown_service', $failures);
        self::assertStringNotContainsString('good:', $failures);
        self::assertStringNotContainsString('good_service', $failures);
        self::assertSame(6, $result['controllers']);
    }

    public function test_it_fails_on_a_missing_method(): void
    {
        $result = $this->probe(['no_method' => GoodController::class.'::nothing'], []);

        self::assertCount(1, $result['failures']);
        self::assertStringContainsString('no_method', $result['failures'][0]);
    }

    public function test_the_real_routes_have_no_failure(): void
    {
        self::bootKernel();
        $result = static::getContainer()->get(ControllerAttributeProbe::class)->probe();
        $router = static::getContainer()->get('router');

        self::assertSame([], $result['failures']);
        self::assertGreaterThan(0, $result['controllers']);
        self::assertGreaterThan(0, $result['attributes']);
        self::assertSame(
            'league.oauth2_server.controller.token::indexAction',
            $router->getRouteCollection()->get('oauth2_token')?->getDefault('_controller'),
        );
    }

    /**
     * @param array<string, string>       $controllers
     * @param array<string, class-string> $map
     *
     * @return array{routes: int, controllers: int, attributes: int, failures: list<string>}
     */
    private function probe(array $controllers, array $map): array
    {
        $collection = new RouteCollection();
        foreach ($controllers as $name => $controller) {
            $collection->add($name, new Route('/'.$name, ['_controller' => $controller]));
        }

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        return new ControllerAttributeProbe($router, $map)->probe();
    }
}
