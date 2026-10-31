<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\ValueObject\AccountLimit;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use App\Module\Bridge\ValueObject\WindowLimit;
use App\Module\Bridge\ValueObject\WindowLimitState;
use Psr\Clock\ClockInterface;

/**
 * Reads the latest reading of each usage window of an account and says
 * whether the account takes new work.
 */
final readonly class AccountLimits
{
    /** The percentage of use at which an account stops taking new work, when its rule file names none. */
    public const int DEFAULT_STOP_AT = 95;

    public function __construct(
        private BridgeAccountReadingRepository $bridgeAccountReadings,
        private ClockInterface $clock,
    ) {
    }

    /** @return array<string, AccountLimit> keyed by account name, for each account with a reading */
    public function forBridge(Bridge $bridge): array
    {
        $stopAt = [];
        foreach ($bridge->accounts ?? [] as $row) {
            $stopAt[$row['name']] = $row['stopAt'] ?? self::DEFAULT_STOP_AT;
        }

        $byAccount = [];
        foreach ($this->bridgeAccountReadings->latestOfBridge($bridge->owner->id ?? throw new \LogicException('A stored owner always has an id.'), $bridge->id) as $reading) {
            $byAccount[$reading->account][] = $reading;
        }

        $limits = [];
        foreach ($byAccount as $account => $latest) {
            $limits[$account] = $this->limitOf((string) $account, $stopAt[$account] ?? self::DEFAULT_STOP_AT, $latest);
        }

        return $limits;
    }

    /** @return array<string, AccountLimit> keyed by account name, for each account that takes no new work */
    public function blockedOf(Bridge $bridge): array
    {
        return array_filter($this->forBridge($bridge), static fn (AccountLimit $limit): bool => $limit->blocked());
    }

    /** @param list<AccountReadingReport> $latest the newest reading of each window of the account */
    public function limitOf(string $account, int $stopAt, array $latest): AccountLimit
    {
        $now = $this->clock->now();

        return new AccountLimit($account, array_map(
            static fn (AccountReadingReport $reading): WindowLimit => new WindowLimit($reading, self::stateOf($reading, $stopAt, $now)),
            $latest,
        ), $stopAt);
    }

    private static function stateOf(AccountReadingReport $reading, int $stopAt, \DateTimeImmutable $now): WindowLimitState
    {
        if ($reading->resetsAt <= $now) {
            return WindowLimitState::Unknown;
        }
        if ($reading->limited) {
            return WindowLimitState::AtLimit;
        }

        return null !== $reading->usedPercent && $reading->usedPercent >= $stopAt ? WindowLimitState::AtStop : WindowLimitState::Open;
    }
}
