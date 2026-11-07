<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\GitHub\View\GitHubSiteFacts;
use App\Module\Project\Entity\Project;

/** One project of the caller, with what the bridge collects from its tool calls. */
final readonly class ListedSite
{
    /**
     * @param list<string>  $subcommandPrograms
     * @param ?list<string> $skillPackages      the skill packages the project's template names, or null when its stored copy names none
     */
    public function __construct(
        public Project $project,
        public bool $collectFullText,
        public array $subcommandPrograms,
        public ?array $skillPackages,
        public ?bool $needsProfile,
        public bool $discoveryRemains,
        public GitHubSiteFacts $github,
    ) {
    }
}
