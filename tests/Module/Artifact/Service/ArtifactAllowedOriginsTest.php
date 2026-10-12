<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Artifact\Service\ArtifactAllowedOrigins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtifactAllowedOriginsTest extends TestCase
{
    public function test_it_keeps_https_origins_in_order_without_duplicates(): void
    {
        self::assertSame(
            ['https://fonts.gstatic.com', 'https://cdn.example.com:8443', 'https://*.example.org'],
            ArtifactAllowedOrigins::parse("  https://fonts.gstatic.com\nHTTPS://CDN.example.com:8443  https://fonts.gstatic.com\thttps://*.example.org "),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'wildcard alone' => ['*'];
        yield 'wildcard https' => ['https://*'];
        yield 'wildcard top level' => ['https://*.com'];
        yield 'any https' => ['https:'];
        yield 'plain http' => ['http://cdn.example.com'];
        yield 'scheme relative' => ['//cdn.example.com'];
        yield 'path' => ['https://cdn.example.com/lib'];
        yield 'semicolon injection' => ['https://a.example.com;script-src'];
        yield 'quote' => ["'unsafe-eval'"];
        yield 'quoted host' => ["https://a.example.com'"];
        yield 'comma' => ['https://a.example.com,https://b.example.com'];
        yield 'data scheme' => ['data:'];
        yield 'single label host' => ['https://localhost'];
        yield 'user info' => ['https://user@a.example.com'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejected')]
    public function test_it_drops_a_token_that_is_not_a_plain_https_origin(string $token): void
    {
        self::assertSame(['https://ok.example.com'], ArtifactAllowedOrigins::parse($token.' https://ok.example.com'));
    }
}
