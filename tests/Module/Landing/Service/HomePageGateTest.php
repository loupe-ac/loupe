<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Service;

use App\Module\Landing\Service\HomePageGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HomePageGateTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, bool}> */
    public static function cases(): iterable
    {
        yield 'host matches' => ['https://loupe.ac', 'loupe.ac', true];
        yield 'host matches with a trailing slash and a port' => ['https://loupe.ac:8443/', 'loupe.ac', true];
        yield 'host matches in another case' => ['https://Loupe.AC', 'loupe.ac', true];
        yield 'another host' => ['https://loupe.example.com', 'loupe.ac', false];
        yield 'a subdomain of the host' => ['https://app.loupe.ac', 'loupe.ac', false];
        yield 'the host as a suffix of another host' => ['https://notloupe.ac', 'loupe.ac', false];
        yield 'an address with no host' => ['loupe.ac', 'loupe.ac', false];
        yield 'no hosted host shows the page' => ['https://loupe.example.com', null, true];
        yield 'no hosted host and no address shows the page' => ['', null, true];
    }

    #[DataProvider('cases')]
    public function test_shows(string $appUrl, ?string $hostedHost, bool $expected): void
    {
        self::assertSame($expected, new HomePageGate($appUrl, $hostedHost)->shows());
    }
}
