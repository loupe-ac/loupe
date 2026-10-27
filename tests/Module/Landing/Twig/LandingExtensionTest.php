<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Twig;

use App\Module\Landing\Service\ComparisonPages;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class LandingExtensionTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function paths(): iterable
    {
        yield 'a path' => ['/privacy', '/privacy'];
        yield 'the root' => ['/', '/'];
    }

    #[DataProvider('paths')]
    public function test_public_url_prefixes_the_configured_address(string $path, string $suffix): void
    {
        $appUrl = static::getContainer()->getParameter('app.url');
        self::assertIsString($appUrl);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $url = $twig->createTemplate('{{ public_url(path) }}')->render(['path' => $path]);

        self::assertSame(rtrim($appUrl, '/').$suffix, $url);
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function routes(): iterable
    {
        yield 'a public route' => ['app_privacy', true];
        yield 'a comparison page' => ['app_compare', true];
        yield 'the comparison list' => ['app_compare_list', true];
        yield 'the sign-in page' => ['app_login', true];
        yield 'an app route' => ['app_projects', false];
        yield 'no route' => [null, false];
    }

    #[DataProvider('routes')]
    public function test_is_public_page_reads_the_route_of_the_main_request(?string $route, bool $expected): void
    {
        $request = new Request();
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }
        $stack = static::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        self::assertSame($expected, '1' === $twig->createTemplate('{{ is_public_page() ? 1 : 0 }}')->render());
    }

    public function test_public_page_paths_lists_the_public_paths(): void
    {
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $paths = json_decode($twig->createTemplate('{{ public_page_paths()|json_encode|raw }}')->render([]), true, flags: \JSON_THROW_ON_ERROR);

        $comparisons = static::getContainer()->get(ComparisonPages::class);
        self::assertInstanceOf(ComparisonPages::class, $comparisons);
        $pagePaths = array_map(static fn ($page): string => '/compare/'.$page->slug, $comparisons->all());
        self::assertSame(['/', '/privacy', '/terms', '/ai-policy', '/compare', '/waitlist', '/login', '/register', ...$pagePaths], $paths);
    }
}
