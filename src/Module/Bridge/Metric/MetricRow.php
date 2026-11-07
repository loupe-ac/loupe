<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use Symfony\Component\Uid\Uuid;

/** One run or one card behind a metric. A null value is unknown and stays out of the statistics. */
final readonly class MetricRow
{
    public function __construct(
        /** The run id or the card id. */
        public Uuid $unitId,
        public ?int $cardNumber,
        public ?string $group,
        public \DateTimeImmutable $time,
        public int|float|null $value,
        /** Set for a split by account, by bridge or by account usage. */
        public ?MetricGroupKey $groupKey = null,
    ) {
    }

    /** The same row, with its time moved forward by the interval. */
    public function movedBy(\DateInterval $interval): self
    {
        return new self($this->unitId, $this->cardNumber, $this->group, $this->time->add($interval), $this->value, $this->groupKey);
    }
}
