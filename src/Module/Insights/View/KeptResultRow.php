<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Insights\Entity\InsightsKeptResult;

/** What a reader gets from the kept results table: the settings the result fits, when it was computed and the result itself. */
final readonly class KeptResultRow
{
    public function __construct(
        public string $settingsHash,
        public \DateTimeImmutable $computedAt,
        /** @see InsightsKeptResult::encode() */
        public string $payload,
    ) {
    }

    /** The kept object, or null when the payload no longer reads. */
    public function result(): ?object
    {
        return InsightsKeptResult::decode($this->payload);
    }
}
