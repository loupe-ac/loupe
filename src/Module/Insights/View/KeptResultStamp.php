<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** The settings a kept result fits and when it was computed, with no payload. */
final readonly class KeptResultStamp
{
    public function __construct(
        public string $settingsHash,
        public \DateTimeImmutable $computedAt,
    ) {
    }
}
