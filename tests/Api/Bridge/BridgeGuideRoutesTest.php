<?php

declare(strict_types=1);

namespace App\Tests\Api\Bridge;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

final class BridgeGuideRoutesTest extends KernelTestCase
{
    private const string UUID = '0b0e4a8c-6f3e-4b55-9d0a-1c2d3e4f5a6b';

    public function test_every_v1_route_in_the_bridge_guide_exists(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        preg_match_all('#`(?:(GET|POST|PUT|PATCH|DELETE) )?(/api/v1/[^`?]+)[^`]*`#', $this->guide(), $routes, \PREG_SET_ORDER);
        self::assertNotEmpty($routes);

        foreach ($routes as [$mention, $method, $path]) {
            self::assertTrue($this->routeExists($router, $method, $path), 'No route answers '.$mention);
        }
    }

    public function test_the_bridge_guide_shows_no_flat_error_body(): void
    {
        self::assertStringNotContainsString('{"error"', $this->guide());
    }

    private function routeExists(RouterInterface $router, string $method, string $path): bool
    {
        $concretePath = str_replace('{number}', '1', (string) preg_replace('/\{(?!number\})[^}]+\}/', self::UUID, $path));
        $context = $router->getContext();

        foreach ('' === $method ? ['GET', 'PUT', 'POST', 'DELETE'] : [$method] as $candidate) {
            $context->setMethod($candidate);
            try {
                $router->match($concretePath);

                return true;
            } catch (RoutingException) {
                continue;
            }
        }

        return false;
    }

    private function guide(): string
    {
        $guide = file_get_contents(\dirname(__DIR__, 3).'/docs/extending/cli-bridge.md');
        self::assertIsString($guide);

        return $guide;
    }
}
