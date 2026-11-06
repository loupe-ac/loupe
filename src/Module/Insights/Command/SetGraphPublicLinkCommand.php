<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;

final readonly class SetGraphPublicLinkCommand
{
    public function __construct(
        public InsightsSavedGraph $graph,
        /** The switch of the share dialog: true shares the graph, false stops sharing it. */
        public bool $enabled,
    ) {
    }
}
