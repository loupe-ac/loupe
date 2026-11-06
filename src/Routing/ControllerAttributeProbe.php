<?php

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

/**
 * Creates the attributes of every routed controller, as the kernel does when a
 * request reaches it. A class that the image does not install fails here, at
 * build time, and not on the first request.
 *
 * The kernel skips an attribute whose class does not exist. The probe reports
 * it, because a skipped access attribute would drop its check with no error.
 */
final readonly class ControllerAttributeProbe
{
    /**
     * @param array<string, class-string> $controllerServiceClasses filled by ControllerServiceClassPass
     */
    public function __construct(
        private RouterInterface $router,

        #[Autowire([])]
        private array $controllerServiceClasses,
    ) {
    }

    /**
     * @return array{routes: int, controllers: int, attributes: int, failures: list<string>}
     */
    public function probe(): array
    {
        $routes = 0;
        $attributes = 0;
        $failures = [];
        $seen = [];

        foreach ($this->router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (null === $controller) {
                continue;
            }

            ++$routes;

            if (!\is_string($controller)) {
                $failures[] = \sprintf('%s: the controller is not a string', $name);

                continue;
            }

            if (isset($seen[$controller])) {
                continue;
            }
            $seen[$controller] = true;

            try {
                [$class, $method] = $this->resolve($controller);
                $reflections = [new \ReflectionClass($class), new \ReflectionMethod($class, $method)];
            } catch (\ReflectionException|\LogicException $e) {
                $failures[] = \sprintf('%s: %s: %s', $name, $controller, $e->getMessage());

                continue;
            }

            foreach ($reflections as $reflection) {
                foreach ($reflection->getAttributes() as $attribute) {
                    ++$attributes;

                    try {
                        $attribute->newInstance();
                    } catch (\Throwable $e) {
                        $failures[] = \sprintf('%s: %s: %s: %s', $name, $controller, $attribute->getName(), $e->getMessage());
                    }
                }
            }
        }

        return [
            'routes' => $routes,
            'controllers' => \count($seen),
            'attributes' => $attributes,
            'failures' => $failures,
        ];
    }

    /**
     * @return array{class-string, string}
     */
    private function resolve(string $controller): array
    {
        $method = '__invoke';
        $id = $controller;
        if (str_contains($controller, '::')) {
            [$id, $method] = explode('::', $controller, 2);
        }

        if (class_exists($id)) {
            return [$id, $method];
        }

        return [$this->controllerServiceClasses[$id] ?? throw new \LogicException(\sprintf('"%s" is neither a class nor a known controller service', $id)), $method];
    }
}
