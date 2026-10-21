<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Site\SiteHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteHostTest extends TestCase
{
    public function test_a_split_instance_owns_its_site_host_only(): void
    {
        $siteHost = new SiteHost(' Loupe.AC ', 'https://app.loupe.ac');

        self::assertTrue($siteHost->isSet());
        self::assertTrue($siteHost->isSplit());
        self::assertTrue($siteHost->owns('LOUPE.ac'));
        self::assertFalse($siteHost->owns('app.loupe.ac'));
        self::assertFalse($siteHost->owns('notloupe.ac'));
        self::assertTrue($siteHost->isSiteRequest('loupe.ac'));
        self::assertFalse($siteHost->isSiteRequest('app.loupe.ac'));
        self::assertTrue($siteHost->servesSiteOn('loupe.ac'));
        self::assertFalse($siteHost->servesSiteOn('app.loupe.ac'));
        self::assertSame('https://loupe.ac', $siteHost->origin());
    }

    public function test_an_address_is_read_as_its_host(): void
    {
        $siteHost = new SiteHost('https://app.loupe.dev.localhost:8443/', 'https://app.loupe.dev.localhost');

        self::assertSame('app.loupe.dev.localhost', $siteHost->host);
        self::assertFalse($siteHost->isSplit());
    }

    public function test_the_app_host_as_site_host_is_one_host_with_both_roles(): void
    {
        $siteHost = new SiteHost('localhost', 'http://localhost:8080/');

        self::assertTrue($siteHost->isSet());
        self::assertFalse($siteHost->isSplit());
        self::assertFalse($siteHost->isSiteRequest('localhost'));
        self::assertTrue($siteHost->servesSiteOn('localhost'));
        self::assertTrue($siteHost->servesSiteOn('anything.example'));
        self::assertSame('http://localhost:8080', $siteHost->origin());
    }

    #[DataProvider('unsetValues')]
    public function test_no_site_host_means_no_site_role(?string $configured): void
    {
        $siteHost = new SiteHost($configured, 'https://loupe.example.com');

        self::assertFalse($siteHost->isSet());
        self::assertFalse($siteHost->isSplit());
        self::assertFalse($siteHost->owns('loupe.example.com'));
        self::assertFalse($siteHost->servesSiteOn('loupe.example.com'));
        self::assertNull($siteHost->origin());
    }

    /** @return iterable<string, array{?string}> */
    public static function unsetValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'blank' => ['  '];
    }
}
