<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\Repository\BridgeAccountTryNowRepository;
use App\Module\Bridge\Service\AccountLimits;
use App\Module\Bridge\Service\TryNowOverrides;
use App\Module\Bridge\ValueObject\AccountLimitCause;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use App\Module\Bridge\ValueObject\WindowLimitState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class AccountLimitsTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-07 12:00:00';

    public function test_a_window_at_its_limit_blocks_with_the_cause_limit(): void
    {
        $limit = $this->limits()->limitOf('work', 95, [self::reading('session', 10.0, '2026-10-07 15:00:00', limited: true)]);

        self::assertSame(WindowLimitState::AtLimit, $limit->windows[0]->state);
        self::assertTrue($limit->blocked());
        self::assertSame(AccountLimitCause::Limit, $limit->cause());
    }

    public function test_a_window_at_the_stop_value_blocks_with_the_cause_stop(): void
    {
        $limit = $this->limits()->limitOf('work', 95, [self::reading('week', 95.0, '2026-10-09 00:00:00')]);

        self::assertSame(WindowLimitState::AtStop, $limit->windows[0]->state);
        self::assertTrue($limit->blocked());
        self::assertSame(AccountLimitCause::Stop, $limit->cause());
    }

    public function test_a_window_below_the_stop_value_is_open(): void
    {
        $limit = $this->limits()->limitOf('work', 95, [self::reading('week', 94.9, '2026-10-09 00:00:00')]);

        self::assertSame(WindowLimitState::Open, $limit->windows[0]->state);
        self::assertFalse($limit->blocked());
        self::assertNull($limit->cause());
        self::assertNull($limit->releaseAt());
    }

    public function test_the_stop_value_of_the_account_decides(): void
    {
        $limit = $this->limits()->limitOf('work', 50, [self::reading('week', 60.0, '2026-10-09 00:00:00')]);

        self::assertSame(WindowLimitState::AtStop, $limit->windows[0]->state);
    }

    public function test_a_null_use_never_reaches_the_stop_value(): void
    {
        $open = $this->limits()->limitOf('work', 1, [self::reading('session', null, '2026-10-07 15:00:00')]);
        $limited = $this->limits()->limitOf('work', 1, [self::reading('session', null, '2026-10-07 15:00:00', limited: true)]);

        self::assertSame(WindowLimitState::Open, $open->windows[0]->state);
        self::assertSame(WindowLimitState::AtLimit, $limited->windows[0]->state);
    }

    public function test_a_window_whose_reset_passed_is_unknown_and_never_blocks(): void
    {
        $limit = $this->limits()->limitOf('work', 95, [
            self::reading('session', 100.0, '2026-10-07 11:59:59', limited: true),
            self::reading('week', 100.0, self::NOW, limited: true),
        ]);

        self::assertSame([WindowLimitState::Unknown, WindowLimitState::Unknown], array_map(static fn ($window) => $window->state, $limit->windows));
        self::assertFalse($limit->blocked());
        self::assertNull($limit->releaseAt());
    }

    public function test_the_release_time_is_the_latest_reset_of_the_blocking_windows(): void
    {
        $limit = $this->limits()->limitOf('work', 95, [
            self::reading('session', 100.0, '2026-10-07 15:00:00', limited: true),
            self::reading('week', 96.0, '2026-10-09 09:00:00'),
            self::reading('opus', 10.0, '2026-10-20 09:00:00'),
            self::reading('old', 100.0, '2026-10-01 09:00:00', limited: true),
        ]);

        self::assertEquals(new \DateTimeImmutable('2026-10-09 09:00:00', new \DateTimeZone('UTC')), $limit->releaseAt());
        self::assertSame(AccountLimitCause::Limit, $limit->cause());
    }

    public function test_it_reads_the_stop_value_of_each_account_from_the_bridge_and_defaults_to_95(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'limits-for-bridge@example.com');
        $bridge = $this->seedBridge($em, $owner, Uuid::v4(), accounts: [
            ['name' => 'strict', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null, 'stopAt' => 50],
            ['name' => 'plain', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null],
        ]);
        $future = '2026-10-09 00:00:00';
        $this->seedAccountReading($em, $bridge, '2026-10-07 11:00:00', 'strict', 'week', 60.0, $future);
        $this->seedAccountReading($em, $bridge, '2026-10-07 11:00:00', 'plain', 'week', 60.0, $future);
        $this->seedAccountReading($em, $bridge, '2026-10-07 11:30:00', 'plain', 'week', 96.0, $future);
        $this->seedAccountReading($em, $bridge, '2026-10-07 11:00:00', 'unlisted', 'week', 96.0, $future);

        $limits = $this->limits()->forBridge($bridge);

        self::assertSame(['plain', 'strict', 'unlisted'], array_keys($limits));
        self::assertSame(AccountLimitCause::Stop, $limits['strict']->cause());
        self::assertSame(AccountLimitCause::Stop, $limits['plain']->cause());
        self::assertSame(AccountLimitCause::Stop, $limits['unlisted']->cause());
        self::assertCount(1, $limits['plain']->windows);
        self::assertSame(96.0, $limits['plain']->windows[0]->reading->usedPercent);
    }

    private function limits(): AccountLimits
    {
        self::bootKernel();
        $readings = self::getContainer()->get(BridgeAccountReadingRepository::class);
        self::assertInstanceOf(BridgeAccountReadingRepository::class, $readings);

        $tryNows = self::getContainer()->get(BridgeAccountTryNowRepository::class);
        self::assertInstanceOf(BridgeAccountTryNowRepository::class, $tryNows);
        $clock = new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));

        return new AccountLimits($readings, $clock, new TryNowOverrides($tryNows, $clock));
    }

    private static function reading(string $window, ?float $usedPercent, string $resetsAt, bool $limited = false): AccountReadingReport
    {
        $utc = new \DateTimeZone('UTC');

        return new AccountReadingReport('work', $window, $usedPercent, new \DateTimeImmutable($resetsAt, $utc), $limited, 'stream', new \DateTimeImmutable('2026-10-07 11:00:00', $utc));
    }
}
