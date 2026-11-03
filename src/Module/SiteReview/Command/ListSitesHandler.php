<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Bridge\Service\ToolCallCollectionSettings;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use Symfony\Component\Uid\Uuid;

final readonly class ListSitesHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private ToolCallCollectionSettings $toolCallCollection,
        private WorkflowBindingRepository $workflowBindings,
    ) {
    }

    public function __invoke(ListSitesCommand $command): ListSitesView
    {
        $projects = $this->projects->findByOwner($command->owner);
        $collection = $this->toolCallCollection->forOwner($command->owner, $projects);
        $skills = $this->workflowBindings->findSkillSettings(array_map(static fn (Project $project): Uuid => $project->id ?? throw new \LogicException('A listed project is not flushed.'), $projects));

        return new ListSitesView(
            sites: array_map(fn (Project $project): ListedSite => new ListedSite(
                project: $project,
                collectFullText: $collection[(string) $project->id]['collectFullText'],
                subcommandPrograms: $collection[(string) $project->id]['subcommandPrograms'],
                skillPackages: $skills[(string) $project->id]['skillPackages'] ?? null,
                needsProfile: $skills[(string) $project->id]['needsProfile'] ?? null,
            ), $projects),
        );
    }
}
