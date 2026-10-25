<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Account\Entity\User;
use App\Module\Notification\Service\PresenceStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\ItemInterface;

final class PresenceStoreTest extends TestCase
{
    private MockClock $clock;
    private ArrayAdapter $cache;
    private PresenceStore $store;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-25 12:00:00');
        $this->cache = new ArrayAdapter();
        $this->store = new PresenceStore($this->cache, $this->clock);
    }

    public function test_a_user_who_never_posted_is_absent(): void
    {
        self::assertFalse($this->store->isPresent($this->user()));
    }

    public function test_a_post_makes_the_user_present_at_once(): void
    {
        $user = $this->user();

        $this->store->record($user);

        self::assertTrue($this->store->isPresent($user));
    }

    public function test_the_user_is_present_up_to_90_seconds_and_absent_after(): void
    {
        $user = $this->user();
        $this->store->record($user);

        $this->clock->sleep(90);
        self::assertTrue($this->store->isPresent($user), '90 seconds old still counts');

        $this->clock->sleep(1);
        self::assertFalse($this->store->isPresent($user), '91 seconds old does not');
    }

    public function test_a_later_post_moves_the_window(): void
    {
        $user = $this->user();
        $this->store->record($user);
        $this->clock->sleep(80);
        $this->store->record($user);
        $this->clock->sleep(80);

        self::assertTrue($this->store->isPresent($user));
    }

    public function test_presence_is_per_user(): void
    {
        $this->store->record($this->user());

        self::assertFalse($this->store->isPresent($this->user()));
    }

    public function test_the_entry_is_stored_under_the_user_key_for_120_seconds(): void
    {
        $user = $this->user();
        $this->store->record($user);

        $item = $this->cache->getItem('presence.'.$user->id?->toRfc4122());
        self::assertTrue($item->isHit());
        $remaining = $item->getMetadata()[ItemInterface::METADATA_EXPIRY] - microtime(true);
        self::assertEqualsWithDelta(120, $remaining, 2);
    }

    public function test_a_stamp_from_the_future_does_not_hold_a_push_back(): void
    {
        $user = $this->user();
        $this->store->record($user);
        $this->clock->modify('-5 seconds');

        self::assertFalse($this->store->isPresent($user));
    }

    private function user(): User
    {
        $user = new User(fullName: 'Riley', email: 'riley-'.uniqid().'@example.com', password: 'x');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v7());

        return $user;
    }
}
