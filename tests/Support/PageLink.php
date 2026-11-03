<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Container\ContainerInterface;
use Symfony\Component\Routing\RouterInterface;

/** Reads a `url` of the public API back through the router, so a test proves which app page it opens. */
final class PageLink
{
    /**
     * @return array<string, mixed> the matched route name as `_route`, and its parameters
     */
    public static function match(ContainerInterface $container, string $url): array
    {
        $router = $container->get('router');
        \assert($router instanceof RouterInterface);
        self::assertAbsolute($url);
        $router->getContext()->setMethod('GET');

        return $router->match((string) parse_url($url, \PHP_URL_PATH));
    }

    private static function assertAbsolute(string $url): void
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException(\sprintf('The link "%s" is not absolute.', $url));
        }
    }
}
