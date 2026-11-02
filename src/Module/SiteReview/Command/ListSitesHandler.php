<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Bridge\Service\ToolCallCollectionSettings;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;

final readonly class ListSitesHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private ToolCallCollectionSettings $toolCallCollection,
    ) {
    }

    public function __invoke(ListSitesCommand $command): ListSitesView
    {
        $projects = $this->projects->findByOwner($command->owner);
        $collection = $this->toolCallCollection->forOwner($command->owner, $projects);

        return new ListSitesView(
            sites: array_map(fn (Project $project): ListedSite => new ListedSite(
                project: $project,
                collectFullText: $collection[(string) $project->id]['collectFullText'],
                subcommandPrograms: $collection[(string) $project->id]['subcommandPrograms'],
            ), $projects),
        );
    }
}
