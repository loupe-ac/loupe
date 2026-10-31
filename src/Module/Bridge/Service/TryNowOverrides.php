<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountTryNow;
use App\Module\Bridge\Repository\BridgeAccountTryNowRepository;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reads and closes the Try now overrides of the accounts of a bridge. An override
 * holds from the press until the first reading that the bridge read after it, or
 * until its minutes run out, whichever comes first.
 */
final readonly class TryNowOverrides
{
    public function __construct(
        private BridgeAccountTryNowRepository $bridgeAccountTryNows,
        private ClockInterface $clock,
    ) {
    }

    /** @return array<string, BridgeAccountTryNow> the open overrides by account name */
    public function openOf(Bridge $bridge): array
    {
        $open = [];
        foreach ($this->bridgeAccountTryNows->findOpenOfBridge($bridge, $this->clock->now()) as $override) {
            $open[$override->account] = $override;
        }

        return $open;
    }

    public function isOpenForAccount(Uuid $ownerId, string $account): bool
    {
        return $this->bridgeAccountTryNows->hasOpenForOwnerAccount($ownerId, $account, $this->clock->now());
    }

    /**
     * Closes each open override of the bridge that a reading of its account, read after the press, answers.
     * The caller flushes. A reading counts even when it equals the stored one, because the person asked
     * whether the limit still holds.
     *
     * @param list<AccountReadingReport> $reports
     *
     * @return bool whether an override closed
     */
    public function closeOnReadings(Bridge $bridge, array $reports): bool
    {
        if ([] === $reports) {
            return false;
        }

        $now = $this->clock->now();
        $closed = false;
        foreach ($this->bridgeAccountTryNows->findOpenOfBridge($bridge, $now) as $override) {
            $answers = array_filter($reports, static fn (AccountReadingReport $report): bool => $report->account === $override->account && $report->readAt > $override->pressedAt);
            if ([] === $answers) {
                continue;
            }

            usort($answers, static fn (AccountReadingReport $a, AccountReadingReport $b): int => $a->readAt <=> $b->readAt);
            $override->closedAt = $now;
            $override->checkedAt = array_first($answers)->readAt;
            $override->stillLimited = array_any($answers, static fn (AccountReadingReport $report): bool => $report->limited);
            $closed = true;
        }

        return $closed;
    }
}
