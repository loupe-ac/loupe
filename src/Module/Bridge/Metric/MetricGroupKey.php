<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/**
 * The structured identity of a series that a split by account, by bridge or by account usage makes. A label cannot
 * be split back into these parts, because a bridge name may hold a slash and a space.
 */
final readonly class MetricGroupKey
{
    public function __construct(
        /** The bridge id as text. */
        public ?string $bridgeId = null,
        public ?string $account = null,
        public ?string $window = null,
    ) {
    }

    /** One string for the three parts. Different parts never give the same string. */
    public function id(): string
    {
        return json_encode([$this->bridgeId, $this->account, $this->window], \JSON_THROW_ON_ERROR);
    }
}
