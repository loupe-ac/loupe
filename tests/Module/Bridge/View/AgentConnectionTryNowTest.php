<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountTryNow;
use App\Module\Bridge\ValueObject\AccountLimit;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use App\Module\Bridge\ValueObject\WindowLimit;
use App\Module\Bridge\ValueObject\WindowLimitState;
use App\Module\Bridge\View\AgentConnection;
use App\Module\Bridge\View\AgentHealthChip;
use App\Module\Bridge\View\BridgeStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** What the Try now control of an account on the Agents page shows. */
final class AgentConnectionTryNowTest extends TestCase
{
    private const string PRESSED = '2026-10-16 12:00:00';

    public function test_a_blocked_account_offers_try_now(): void
    {
        $connection = $this->connection(blocked: true);

        self::assertSame('available', $connection->tryNowState('claude'));
        self::assertTrue($connection->canTryNow('claude'));
        self::assertNull($connection->tryNowCheckedAt('claude'));
    }

    public function test_an_account_at_its_stop_value_offers_try_now_too(): void
    {
        self::assertTrue($this->connection(blocked: true, limited: false)->canTryNow('claude'));
    }

    public function test_an_account_that_takes_work_offers_nothing(): void
    {
        $connection = $this->connection(blocked: false);

        self::assertSame('none', $connection->tryNowState('claude'));
        self::assertFalse($connection->canTryNow('claude'));
    }

    public function test_an_account_with_no_reading_offers_nothing(): void
    {
        self::assertSame('none', $this->connection(blocked: true)->tryNowState('codex'));
    }

    public function test_an_open_override_reads_as_trying_and_offers_no_second_press(): void
    {
        $connection = $this->connection(blocked: true, tryNow: true);

        self::assertSame('trying', $connection->tryNowState('claude'));
        self::assertFalse($connection->canTryNow('claude'));
    }

    public function test_a_bridge_without_the_capability_offers_nothing(): void
    {
        $connection = $this->connection(blocked: true, capability: false);

        self::assertSame('none', $connection->tryNowState('claude'));
        self::assertFalse($connection->canTryNow('claude'));
    }

    public function test_a_press_that_found_the_limit_still_in_place_reads_as_still_limited_with_the_check_time(): void
    {
        $answered = $this->override(stillLimited: true, checkedAt: '2026-10-16 23:58:00');
        $connection = $this->connection(blocked: true, override: $answered);

        self::assertSame('still-limited', $connection->tryNowState('claude'));
        self::assertTrue($connection->canTryNow('claude'));
        self::assertEquals(new \DateTimeImmutable('2026-10-16 23:58:00'), $connection->tryNowCheckedAt('claude'));
    }

    public function test_a_reading_that_changed_after_the_check_is_news_and_hides_the_old_answer(): void
    {
        $answered = $this->override(stillLimited: true, checkedAt: '2026-10-16 23:58:00');
        $connection = $this->connection(blocked: true, override: $answered, readAt: '2026-10-16 23:59:00');

        self::assertSame('available', $connection->tryNowState('claude'));
    }

    public function test_a_press_that_found_the_limit_lifted_leaves_nothing_to_show_once_the_account_takes_work(): void
    {
        $answered = $this->override(stillLimited: false, checkedAt: '2026-10-16 12:05:00');

        self::assertSame('none', $this->connection(blocked: false, override: $answered)->tryNowState('claude'));
    }

    private function override(bool $stillLimited, string $checkedAt): BridgeAccountTryNow
    {
        $override = new BridgeAccountTryNow(Uuid::v7(), $this->bridge(true), 'claude', new \DateTimeImmutable(self::PRESSED));
        $override->closedAt = new \DateTimeImmutable($checkedAt);
        $override->checkedAt = new \DateTimeImmutable($checkedAt);
        $override->stillLimited = $stillLimited;

        return $override;
    }

    private function bridge(bool $capability): Bridge
    {
        $bridge = new Bridge(new User(fullName: 'Riley Chen', email: 'try-now-view@example.com', password: 'x'), Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable(self::PRESSED));
        $bridge->capabilities = $capability ? [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_TRY_NOW] : [Bridge::CAPABILITY_COMMANDS];

        return $bridge;
    }

    private function connection(bool $blocked, bool $limited = true, bool $tryNow = false, bool $capability = true, ?BridgeAccountTryNow $override = null, string $readAt = '2026-10-16 11:00:00'): AgentConnection
    {
        $bridge = $this->bridge($capability);
        $utc = new \DateTimeZone('UTC');
        $reading = new AccountReadingReport('claude', 'week', $limited ? 100.0 : 99.0, new \DateTimeImmutable('2026-10-17 17:00:00', $utc), $limited, 'stream', new \DateTimeImmutable($readAt, $utc));
        $state = $blocked ? ($limited ? WindowLimitState::AtLimit : WindowLimitState::AtStop) : WindowLimitState::Open;
        $limit = new AccountLimit('claude', [new WindowLimit($reading, $state)], 95, $tryNow);
        $status = new BridgeStatus(new \DateTimeImmutable(self::PRESSED), new \DateTimeImmutable(self::PRESSED), false);

        return new AgentConnection($bridge, $status, null, AgentHealthChip::for($bridge, $status), ['claude' => $limit], null === $override ? [] : ['claude' => $override]);
    }
}
