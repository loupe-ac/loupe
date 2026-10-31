<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\AccountLimit;
use App\Module\Bridge\ValueObject\AccountLimitCause;
use App\Module\Bridge\ValueObject\WindowLimit;
use App\Module\Bridge\ValueObject\WindowLimitState;

final readonly class AgentConnection
{
    /** The windows every account draws a bar for, with or without a reading. */
    private const array USAGE_WINDOWS = ['session', 'week'];

    /** @param array<string, AccountLimit> $limits keyed by account name, for each account with a reading */
    public function __construct(
        public Bridge $bridge,
        public BridgeStatus $status,
        public ?AgentUpdateChip $updateChip,
        public AgentHealthChip $healthChip,
        public array $limits = [],
    ) {
    }

    /** A pool with queued work is a problem the reader should see without opening the section. */
    public function poolsNeedAttention(): bool
    {
        return $this->poolTotals()['queued'] > 0;
    }

    /** @return array{inUse: int, size: int, queued: int} */
    public function poolTotals(): array
    {
        $totals = ['inUse' => 0, 'size' => 0, 'queued' => 0];
        foreach ($this->bridge->workerPools ?? [] as $pool) {
            $totals['inUse'] += $pool['inUse'];
            $totals['size'] += $pool['size'];
            $totals['queued'] += $pool['queued'];
        }

        return $totals;
    }

    /** The colour of the dot in the summary of the pools section. */
    public function poolsTone(): string
    {
        return $this->poolsNeedAttention() ? 'pending' : 'ok';
    }

    /** A failing account, or an account that takes no new work, is a problem. */
    public function accountsNeedAttention(): bool
    {
        return 'ok' !== $this->accountsTone();
    }

    /** The colour of the dot in the summary of the accounts section: the worst of the accounts. */
    public function accountsTone(): string
    {
        $tone = 'ok';
        foreach ($this->accountStatuses() as $status) {
            if (\in_array($status['status'], ['failing', 'at-limit'], true)) {
                return 'danger';
            }
            if ('near-limit' === $status['status']) {
                $tone = 'pending';
            }
        }

        return $tone;
    }

    /**
     * What the summary line says about each account.
     *
     * @return list<array{name: string, status: 'failing'|'at-limit'|'near-limit'|'no-reading'|'unknown'|'ok'}>
     */
    public function accountStatuses(): array
    {
        $statuses = [];
        foreach ($this->bridge->accounts ?? [] as $account) {
            $statuses[] = ['name' => $account['name'], 'status' => $this->statusOf($account['name'], 'failing' === $account['state'])];
        }

        return $statuses;
    }

    /**
     * The bars of one account: the session and the week always, then the other windows by name.
     * A window with no reading yet has a null limit, and draws as Unknown.
     *
     * @return list<array{window: string, limit: ?WindowLimit}>
     */
    public function usageBars(string $account): array
    {
        $byWindow = [];
        foreach ($this->limits[$account]->windows ?? [] as $window) {
            $byWindow[$window->reading->window] = $window;
        }
        $others = array_values(array_diff(array_map(strval(...), array_keys($byWindow)), self::USAGE_WINDOWS));
        sort($others);

        return array_map(
            static fn (string $name): array => ['window' => $name, 'limit' => $byWindow[$name] ?? null],
            [...self::USAGE_WINDOWS, ...$others],
        );
    }

    /**
     * A failing or blocked account reads before an Unknown bar, and an Unknown bar before ok.
     *
     * @return 'failing'|'at-limit'|'near-limit'|'no-reading'|'unknown'|'ok'
     */
    private function statusOf(string $name, bool $failing): string
    {
        if ($failing) {
            return 'failing';
        }
        $limit = $this->limits[$name] ?? null;
        if (null === $limit || [] === $limit->windows) {
            return 'no-reading';
        }

        return match ($limit->cause()) {
            AccountLimitCause::Limit => 'at-limit',
            AccountLimitCause::Stop => 'near-limit',
            null => array_any(
                $this->usageBars($name),
                static fn (array $bar): bool => null === $bar['limit'] || WindowLimitState::Unknown === $bar['limit']->state,
            ) ? 'unknown' : 'ok',
        };
    }
}
