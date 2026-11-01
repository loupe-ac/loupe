<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/**
 * A metric the workflow template declares. A count uses `filter` and may use `splitBy`. A rate uses `outcome`, or
 * `filter` for "matched at least once". A duration uses `from` and `to`, and `fromLast` when it starts at the last
 * `from` event. `alsoFrom` adds more starts, and the duration then starts at the earliest event of all of them.
 */
final readonly class TemplateMetric
{
    /**
     * @param string              $label    plain English text, not a translation key
     * @param list<DurationEvent> $alsoFrom the starts after `from`
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public MetricType $type,
        public ?CountFilter $filter = null,
        public ?string $splitBy = null,
        public ?string $outcome = null,
        public ?DurationEvent $from = null,
        public bool $fromLast = false,
        public ?DurationEvent $to = null,
        public array $alsoFrom = [],
    ) {
    }
}
