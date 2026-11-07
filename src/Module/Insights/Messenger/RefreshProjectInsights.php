<?php

declare(strict_types=1);

namespace App\Module\Insights\Messenger;

/** Refreshes the items of a project that read one kind of data, after the grouping delay. */
final readonly class RefreshProjectInsights
{
    public function __construct(
        public string $projectId,
        /** The value of an InsightsDataKind. */
        public string $kind,
        /** Set for the kind experiment: the one shared report to refresh. */
        public ?string $experiment = null,
    ) {
    }
}
