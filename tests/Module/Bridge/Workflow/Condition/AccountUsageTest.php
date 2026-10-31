<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\ValueObject\NamedAccountReading;
use App\Module\Bridge\Workflow\AccountUsageFacts;
use App\Module\Bridge\Workflow\Condition\AccountUsage;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class AccountUsageTest extends TestCase
{
    private const string NOW = '2026-10-16 12:00:00';

    private const array PARAMS = ['account' => 'mac/work', 'window' => 'week', 'above' => 80];

    public function test_a_percentage_above_the_threshold_holds(): void
    {
        self::assertTrue(new AccountUsage()->evaluate(self::facts([self::reading(usedPercent: 80.5)]), self::PARAMS));
    }

    public function test_a_percentage_at_or_under_the_threshold_does_not_hold(): void
    {
        self::assertFalse(new AccountUsage()->evaluate(self::facts([self::reading(usedPercent: 80.0)]), self::PARAMS));
        self::assertFalse(new AccountUsage()->evaluate(self::facts([self::reading(usedPercent: 12.0)]), self::PARAMS));
    }

    public function test_a_use_past_the_limit_holds(): void
    {
        self::assertTrue(new AccountUsage()->evaluate(self::facts([self::reading(usedPercent: 140.0)]), ['above' => 100] + self::PARAMS));
    }

    public function test_a_reading_with_no_use_value_does_not_hold(): void
    {
        self::assertFalse(new AccountUsage()->evaluate(self::facts([self::reading(usedPercent: null)]), self::PARAMS));
    }

    public function test_a_window_that_reset_is_unknown_and_does_not_hold(): void
    {
        self::assertFalse(new AccountUsage()->evaluate(self::facts([self::reading(usedPercent: 95.0, resetsAt: self::NOW)]), self::PARAMS));
        self::assertFalse(new AccountUsage()->evaluate(self::facts([self::reading(usedPercent: 95.0, resetsAt: '2026-10-16 11:00:00')]), self::PARAMS));
    }

    public function test_no_reading_of_the_window_does_not_hold(): void
    {
        self::assertFalse(new AccountUsage()->evaluate(self::facts([]), self::PARAMS));
        self::assertFalse(new AccountUsage()->evaluate(self::facts([self::reading(window: 'session')]), self::PARAMS));
        self::assertFalse(new AccountUsage()->evaluate(self::facts([self::reading(account: 'play')]), self::PARAMS));
    }

    public function test_a_renamed_bridge_does_not_hold(): void
    {
        self::assertFalse(new AccountUsage()->evaluate(self::facts([self::reading(bridgeName: 'laptop')]), self::PARAMS));
    }

    public function test_the_account_splits_at_the_last_slash(): void
    {
        $facts = self::facts([self::reading(bridgeName: 'home/mac')]);

        self::assertTrue(new AccountUsage()->evaluate($facts, ['account' => 'home/mac/work'] + self::PARAMS));
        self::assertFalse(new AccountUsage()->evaluate($facts, ['account' => 'home/mac/play'] + self::PARAMS));
        self::assertFalse(new AccountUsage()->evaluate($facts, ['account' => 'home/work'] + self::PARAMS));
        self::assertFalse(new AccountUsage()->evaluate($facts, ['account' => 'work'] + self::PARAMS));
    }

    public function test_the_condition_changes_at_the_reset_of_the_compared_window(): void
    {
        $facts = self::facts([
            self::reading(resetsAt: '2026-10-16 15:00:00'),
            self::reading(window: 'session', resetsAt: '2026-10-16 13:00:00'),
        ]);

        self::assertEquals(new \DateTimeImmutable('2026-10-16 15:00:00'), new AccountUsage()->changesAt($facts, self::PARAMS));
    }

    public function test_the_condition_has_no_change_time_without_a_future_reset(): void
    {
        self::assertNull(new AccountUsage()->changesAt(self::facts([]), self::PARAMS));
        self::assertNull(new AccountUsage()->changesAt(self::facts([self::reading(resetsAt: self::NOW)]), self::PARAMS));
    }

    public function test_the_condition_names_its_facts_and_its_waiting_sentences(): void
    {
        $condition = new AccountUsage();

        self::assertSame('bridge.account.usage', AccountUsage::key());
        self::assertSame([AccountUsageFacts::class], $condition->reads(self::PARAMS));
        self::assertSame('workflow.waiting.bridge_account_usage', $condition->waitingFor(self::PARAMS)->getMessage());
        self::assertSame('workflow.waiting.not.bridge_account_usage', $condition->waitingFor(self::PARAMS, negated: true)->getMessage());
        self::assertSame(['%account%' => 'mac/work', '%window%' => 'week', '%above%' => '80'], $condition->waitingFor(self::PARAMS)->getParameters());
    }

    /** @param list<NamedAccountReading> $readings */
    private static function facts(array $readings): Facts
    {
        return FactsMother::facts(now: new \DateTimeImmutable(self::NOW), provided: [AccountUsageFacts::class => new AccountUsageFacts($readings)]);
    }

    private static function reading(string $bridgeName = 'mac', string $account = 'work', string $window = 'week', ?float $usedPercent = 90.0, string $resetsAt = '2026-10-17 12:00:00'): NamedAccountReading
    {
        return new NamedAccountReading($bridgeName, $account, $window, $usedPercent, new \DateTimeImmutable($resetsAt));
    }
}
