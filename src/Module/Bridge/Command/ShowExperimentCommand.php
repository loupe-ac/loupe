<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Experiment\ExperimentCardPosition;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class ShowExperimentCommand
{
    public function __construct(
        public Project $project,
        public string $experiment,
        /** The page of the Cards tab. */
        public int $page = 1,
        /** Narrows the Cards tab to the kept cards of one variant. */
        public ?string $variant = null,
        /** Narrows the Cards tab to the cards the report leaves out. */
        public bool $leftOutOnly = false,
        /** The Cards tab shows no metric, so it skips the bootstraps. */
        public bool $withMetrics = true,
        /** Walks the cards by position with `limit` rows, instead of by page. The page number is then ignored. */
        public ?int $limit = null,
        /** The last row of the previous page of a walk. Null starts at the first row. */
        public ?ExperimentCardPosition $after = null,
        /** Fills the pull request URLs of the cards of the page. */
        public bool $withPullRequestUrls = false,
        /** Narrows the Cards tab to one card, whatever its left-out reasons. */
        public ?Uuid $onlyCard = null,
        /** Narrows the Cards tab to the cards the report keeps. */
        public bool $keptOnly = false,
    ) {
    }
}
