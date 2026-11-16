<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubAppInstallationList;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubAppInstallationListTest extends TestCase
{
    private int $requests = 0;
    private MockClock $clock;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-18 13:00:00');
        $this->cache = new ArrayAdapter(clock: $this->clock);
    }

    public function test_it_holds_no_list_until_one_is_read_live(): void
    {
        self::assertNull($this->list()->cached());
    }

    public function test_a_live_read_fills_the_cache_for_ten_minutes(): void
    {
        $list = $this->list();

        $live = $list->live();
        self::assertSame(42, $live[0]->id);
        self::assertSame(42, $list->cached()[0]->id ?? null);
        self::assertSame(1, $this->requests);

        $this->clock->modify('+11 minutes');
        self::assertNull($list->cached());
    }

    public function test_forget_drops_the_list(): void
    {
        $list = $this->list();
        $list->live();

        $list->forget();

        self::assertNull($list->cached());
    }

    private function list(): GitHubAppInstallationList
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);
        $http = new MockHttpClient(function (): MockResponse {
            ++$this->requests;

            return new MockResponse(json_encode([['id' => 42, 'account' => ['login' => 'acme'], 'permissions' => ['contents' => 'write']]], \JSON_THROW_ON_ERROR));
        }, 'https://api.github.com');

        return new GitHubAppInstallationList(new GitHubAppApi($http, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), $this->clock), $this->cache);
    }
}
