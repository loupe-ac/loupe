<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
}
