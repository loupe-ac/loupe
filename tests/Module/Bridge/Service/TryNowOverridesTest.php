<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountTryNow;
use App\Module\Bridge\Service\AccountLimits;
use App\Module\Bridge\Service\AccountReadingRecorder;
use App\Module\Bridge\ValueObject\AccountLimitCause;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use App\Module\Bridge\ValueObject\WindowLimitState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The rules of the Try now override: what it lifts, and when it ends. */
final class TryNowOverridesTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-16 12:00:00';
    private const string RESET = '2026-10-17 17:00:00';

    private MockClock $clock;
    private Bridge $bridge;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $em = $this->em();
        $owner = $this->user($em, 'try-now-rules@example.com');
        $this->bridge = $this->seedBridge($em, $owner, accounts: [
            ['name' => 'claude', 'harness' => 'claude', 'state' => 'ready', 'reason' => null],
            ['name' => 'codex', 'harness' => 'codex', 'state' => 'ready', 'reason' => null],
        ]);
    }

    public function test_an_open_override_lifts_a_hard_limit_and_leaves_the_measured_reading_alone(): void
    {
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'claude', 'week', 100.0, self::RESET, true);
        self::assertTrue($this->limits()->blockedOf($this->bridge)['claude']->blocked());

        $this->press('claude');

        self::assertArrayNotHasKey('claude', $this->limits()->blockedOf($this->bridge));
        $limit = $this->limits()->forBridge($this->bridge)['claude'];
        self::assertSame(WindowLimitState::AtLimit, $limit->windows[0]->state);
        self::assertSame(100.0, $limit->windows[0]->reading->usedPercent);
        self::assertSame(1, $this->readingCount());
    }

    public function test_an_open_override_lifts_the_stop_value_too_and_it_blocks_again_once_the_override_ends(): void
    {
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'claude', 'week', 99.0, self::RESET);
        self::assertSame(AccountLimitCause::Stop, $this->limits()->blockedOf($this->bridge)['claude']->cause());

        $this->press('claude');
        self::assertArrayNotHasKey('claude', $this->limits()->blockedOf($this->bridge));

        $this->clock->sleep(BridgeAccountTryNow::LIFETIME_MINUTES * 60);
        self::assertArrayHasKey('claude', $this->limits()->blockedOf($this->bridge));
    }

    public function test_the_override_holds_until_the_last_second_before_fifteen_minutes(): void
    {
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'claude', 'week', 100.0, self::RESET, true);
        $this->press('claude');

        $this->clock->sleep(BridgeAccountTryNow::LIFETIME_MINUTES * 60 - 1);
        self::assertArrayNotHasKey('claude', $this->limits()->blockedOf($this->bridge));

        $this->clock->sleep(1);
        self::assertArrayHasKey('claude', $this->limits()->blockedOf($this->bridge));
    }

    public function test_it_lifts_the_pressed_account_only(): void
    {
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'claude', 'week', 100.0, self::RESET, true);
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'codex', 'week', 100.0, self::RESET, true);

        $this->press('claude');

        self::assertSame(['codex'], array_keys($this->limits()->blockedOf($this->bridge)));
    }

    public function test_a_reading_read_after_the_press_closes_the_override_and_the_new_reading_decides(): void
    {
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'claude', 'week', 100.0, self::RESET, true);
        $override = $this->press('claude');
        $this->clock->sleep(120);

        $this->recorder()->record($this->bridge, [$this->report('claude', 'week', 99.0, false, '2026-10-16 12:01:30')]);
        $this->em()->flush();

        self::assertNotNull($override->closedAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-16 12:01:30'), $override->checkedAt);
        self::assertFalse($override->stillLimited);
        $blocked = $this->limits()->blockedOf($this->bridge);
        self::assertSame(AccountLimitCause::Stop, $blocked['claude']->cause(), 'a 99 % reading is blocked again once the override closed');
    }

    public function test_a_reading_that_still_shows_the_limit_closes_the_override_and_records_it(): void
    {
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'claude', 'week', 100.0, self::RESET, true);
        $override = $this->press('claude');
        $this->clock->sleep(60);

        // The reading equals the stored one, so no row is stored, and the override still ends.
        $this->recorder()->record($this->bridge, [$this->report('claude', 'week', 100.0, true, '2026-10-16 12:00:50')]);
        $this->em()->flush();

        self::assertNotNull($override->closedAt);
        self::assertTrue($override->stillLimited);
        self::assertSame(1, $this->readingCount());
        self::assertArrayHasKey('claude', $this->limits()->blockedOf($this->bridge));
    }

    public function test_a_reading_read_before_the_press_does_not_close_the_override(): void
    {
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'claude', 'week', 100.0, self::RESET, true);
        $override = $this->press('claude');

        $this->recorder()->record($this->bridge, [$this->report('claude', 'week', 100.0, true, '2026-10-16 11:59:59')]);
        $this->em()->flush();

        self::assertNull($override->closedAt);
        self::assertArrayNotHasKey('claude', $this->limits()->blockedOf($this->bridge));
    }

    public function test_a_reading_of_another_account_does_not_close_the_override(): void
    {
        $this->seedAccountReading($this->em(), $this->bridge, '2026-10-16 11:00:00', 'claude', 'week', 100.0, self::RESET, true);
        $override = $this->press('claude');
        $this->clock->sleep(60);

        $this->recorder()->record($this->bridge, [$this->report('codex', 'week', 10.0, false, '2026-10-16 12:00:50')]);
        $this->em()->flush();

        self::assertNull($override->closedAt);
    }

    private function press(string $account): BridgeAccountTryNow
    {
        $override = new BridgeAccountTryNow(Uuid::v7(), $this->bridge, $account, new \DateTimeImmutable(self::NOW));
        $this->em()->persist($override);
        $this->em()->flush();

        return $override;
    }

    private function report(string $account, string $window, float $usedPercent, bool $limited, string $readAt): AccountReadingReport
    {
        $utc = new \DateTimeZone('UTC');

        return new AccountReadingReport($account, $window, $usedPercent, new \DateTimeImmutable(self::RESET, $utc), $limited, 'stream', new \DateTimeImmutable($readAt, $utc));
    }

    private function readingCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_account_readings');
    }

    private function limits(): AccountLimits
    {
        $limits = self::getContainer()->get(AccountLimits::class);
        self::assertInstanceOf(AccountLimits::class, $limits);

        return $limits;
    }

    private function recorder(): AccountReadingRecorder
    {
        $recorder = self::getContainer()->get(AccountReadingRecorder::class);
        self::assertInstanceOf(AccountReadingRecorder::class, $recorder);

        return $recorder;
    }
}
