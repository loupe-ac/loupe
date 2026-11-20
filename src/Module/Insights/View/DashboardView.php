<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Project\Entity\Project;

final readonly class DashboardView
{
    /** @param list<DashboardTileView> $tiles in order of position */
    public function __construct(
        public Project $project,
        public array $tiles,
        public bool $capReached,
        public int $capLimit,
        /** The oldest computed time among the ready tiles. Null when no tile is ready. */
        public ?\DateTimeImmutable $updatedAt,
        public \DateTimeImmutable $now,
        /** True while an admin has taken down the public links of the project. */
        public bool $publicLinksTakenDown,
    ) {
    }
}
