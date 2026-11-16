<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\GitHubRepositoryTokenStore;
use App\Module\GitHub\Service\GitHubScopedToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;
use Ubermuda\DoctrineExtra\Encryption\Encryptor;

final class GitHubRepositoryTokenStoreTest extends TestCase
{
    private const string NOW = '2026-10-18 13:00:00';
    private const array PERMISSIONS = ['pull_requests' => 'write', 'contents' => 'write'];

    private ArrayAdapter $cache;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->cache = new ArrayAdapter(clock: $this->clock);
    }

    public function test_it_hands_back_a_kept_token_while_fifteen_minutes_or_more_remain(): void
    {
        $store = $this->store(self::key(1));
        $store->keep(7, 9, self::PERMISSIONS, $this->token('ghs_one', '2026-10-18T14:00:00+00:00'));

        $this->clock->modify('+44 minutes');
        $found = $store->find(7, 9, ['contents' => 'write', 'pull_requests' => 'write']);

        self::assertSame('ghs_one', $found?->token);
        self::assertSame('2026-10-18T14:00:00+00:00', $found->expiresAt->format(\DATE_ATOM));
    }

    public function test_it_hands_back_nothing_once_less_than_fifteen_minutes_remain(): void
    {
        $store = $this->store(self::key(1));
        $store->keep(7, 9, self::PERMISSIONS, $this->token('ghs_one', '2026-10-18T14:00:00+00:00'));

        $this->clock->modify('+46 minutes');

        self::assertNull($store->find(7, 9, self::PERMISSIONS));
    }

    public function test_it_keeps_nothing_for_a_token_with_less_than_fifteen_minutes_left(): void
    {
        $store = $this->store(self::key(1));
        $store->keep(7, 9, self::PERMISSIONS, $this->token('ghs_short', '2026-10-18T13:10:00+00:00'));

        self::assertNull($this->store(self::key(1))->find(7, 9, self::PERMISSIONS));
    }

    public function test_it_never_serves_a_token_for_another_installation_repository_or_permission_set(): void
    {
        $store = $this->store(self::key(1));
        $store->keep(7, 9, self::PERMISSIONS, $this->token('ghs_one', '2026-10-18T14:00:00+00:00'));

        self::assertNull($store->find(8, 9, self::PERMISSIONS));
        self::assertNull($store->find(7, 10, self::PERMISSIONS));
        self::assertNull($store->find(7, 9, ['contents' => 'write']));
    }

    public function test_it_rests_the_token_only_encrypted(): void
    {
        $store = $this->store(self::key(1));
        $store->keep(7, 9, self::PERMISSIONS, $this->token('ghs_secret', '2026-10-18T14:00:00+00:00'));

        $keys = $this->cacheKeys();
        self::assertCount(1, $keys);
        $stored = $this->cache->getItem($keys[0])->get();
        self::assertIsString($stored);
        self::assertStringNotContainsString('ghs_secret', $stored);
    }

    public function test_a_value_that_no_key_opens_is_a_miss(): void
    {
        $this->store(self::key(1))->keep(7, 9, self::PERMISSIONS, $this->token('ghs_one', '2026-10-18T14:00:00+00:00'));

        self::assertNull($this->store(self::key(2))->find(7, 9, self::PERMISSIONS));
    }

    public function test_the_previous_key_still_opens_a_value(): void
    {
        $this->store(self::key(1))->keep(7, 9, self::PERMISSIONS, $this->token('ghs_one', '2026-10-18T14:00:00+00:00'));

        self::assertSame('ghs_one', $this->store(self::key(2), self::key(1))->find(7, 9, self::PERMISSIONS)?->token);
    }

    public function test_an_unset_key_keeps_and_finds_nothing(): void
    {
        $store = $this->store('');
        $store->keep(7, 9, self::PERMISSIONS, $this->token('ghs_one', '2026-10-18T14:00:00+00:00'));

        self::assertNull($this->store(self::key(1))->find(7, 9, self::PERMISSIONS));
    }

    private function store(string ...$keys): GitHubRepositoryTokenStore
    {
        return new GitHubRepositoryTokenStore($this->cache, new Encryptor(new EncryptionKeyProvider(array_values($keys))), $this->clock);
    }

    /** @param non-empty-string $token */
    private function token(string $token, string $expiresAt): GitHubScopedToken
    {
        return new GitHubScopedToken($token, new \DateTimeImmutable($expiresAt));
    }

    /** @param int<0, 255> $seed */
    private static function key(int $seed): string
    {
        return base64_encode(str_repeat(\chr($seed), \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /** @return list<string> */
    private function cacheKeys(): array
    {
        return array_keys($this->cache->getValues());
    }
}
