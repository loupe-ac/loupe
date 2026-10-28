<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Workflow\Contract\CountFilter;

/** How many work requests each card opened, by role, kind and reason. */
final readonly class CardRequestCounts
{
    /** @param array<string, list<array{role: ?string, kind: string, reason: string, count: int}>> $byCard card id => rows */
    public function __construct(
        private array $byCard = [],
    ) {
    }

    /** @param list<array{cardId: string, role: ?string, kind: string, reason: string, count: int}> $rows */
    public static function fromRows(array $rows): self
    {
        $byCard = [];
        foreach ($rows as $row) {
            $byCard[$row['cardId']][] = ['role' => $row['role'], 'kind' => $row['kind'], 'reason' => $row['reason'], 'count' => $row['count']];
        }

        return new self($byCard);
    }

    /** The requests of the card that the filter picks. An empty filter field picks everything. */
    public function matching(string $cardId, CountFilter $filter): int
    {
        return array_sum($this->byReason($cardId, $filter));
    }

    /**
     * The same requests, by reason.
     *
     * @return array<string, int> reason => requests, in reason order
     */
    public function byReason(string $cardId, CountFilter $filter): array
    {
        $reasons = [];
        foreach ($this->byCard[$cardId] ?? [] as $row) {
            if ((null === $filter->role || $filter->role === $row['role'])
                && (null === $filter->kind || $filter->kind === $row['kind'])
                && (null === $filter->reason || $filter->reason === $row['reason'])) {
                $reasons[$row['reason']] = ($reasons[$row['reason']] ?? 0) + $row['count'];
            }
        }
        ksort($reasons, \SORT_STRING);

        return $reasons;
    }
}
