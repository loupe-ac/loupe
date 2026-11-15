<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Project\Entity\Project;
use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;
use App\Module\Project\View\ProjectListItem;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Builds the rows of the projects list. Each module counts its own rows for
 * all the projects at once, one grouped query per provider, rather than one per
 * project and figure, which made this list forty queries.
 */
final readonly class ProjectListItems
{
    /** @param iterable<ProjectStatsProviderInterface> $statsProviders */
    public function __construct(
        #[AutowireIterator('app.project_stats_provider')]
        private iterable $statsProviders,
    ) {
    }

    /**
     * @param list<Project> $projects
     *
     * @return list<ProjectListItem>
     */
    public function forProjects(array $projects): array
    {
        $stats = [];
        foreach ($this->statsProviders as $provider) {
            foreach ($provider->statsFor($projects) as $projectId => $contribution) {
                $stats[$projectId] = ($stats[$projectId] ?? new ProjectStats())->merge($contribution);
            }
        }

        return array_map(
            static function (Project $project) use ($stats): ProjectListItem {
                $projectStats = $stats[(string) $project->id] ?? new ProjectStats();

                return new ProjectListItem(
                    project: $project,
                    documentCount: $projectStats->documentCount,
                    commentCount: $projectStats->commentCount,
                    openCount: $projectStats->openCommentCount,
                    openCardCount: $projectStats->openCardCount,
                    openInboxItemCount: $projectStats->openInboxItemCount,
                );
            },
            $projects,
        );
    }
}
