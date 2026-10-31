<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;

final readonly class DashboardView
{
    /** @param list<DashboardTileView> $tiles in order of position */
    public function __construct(
        public Project $project,
        public array $tiles,
        /** The graph the owner just removed, for the Undo notice. Null when there is nothing to undo. */
        public ?InsightsSavedGraph $removed,
        public bool $capReached,
        public int $capLimit,
        /** The oldest computed time among the ready tiles. Null when no tile is ready. */
        public ?\DateTimeImmutable $updatedAt,
        public \DateTimeImmutable $now,
    ) {
    }
}
