<?php

declare(strict_types=1);

namespace App\Module\Insights\Messenger;

/** Computes and keeps the result of one item at once: a graph, or a shared experiment of a project. */
final readonly class RefreshInsightsItem
{
    public function __construct(
        public string $projectId,
        public ?string $graphId,
        public ?string $experiment,
    ) {
    }
}
