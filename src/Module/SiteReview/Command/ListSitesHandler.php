<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Bridge\Service\ToolCallCollectionSettings;
use App\Module\GitHub\Service\ProjectGitHubFacts;
use App\Module\GitHub\View\GitHubSiteFacts;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use Symfony\Component\Uid\Uuid;

final readonly class ListSitesHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private ToolCallCollectionSettings $toolCallCollection,
        private WorkflowBindingRepository $workflowBindings,
        private DiscoveryRunRepository $discoveryRuns,
        private ProjectGitHubFacts $githubFacts,
    ) {
    }

    public function __invoke(ListSitesCommand $command): ListSitesView
    {
        $projects = $this->projects->findByOwner($command->owner);
        $collection = $this->toolCallCollection->forOwner($command->owner, $projects);
        $projectIds = array_map(static fn (Project $project): Uuid => $project->id ?? throw new \LogicException('A listed project is not flushed.'), $projects);
        $skills = $this->workflowBindings->findSkillSettings($projectIds);
        $discoveryStarted = $this->discoveryRuns->findProjectIdsWithDiscoveryStarted($projectIds);

        $github = $this->githubFacts->forOwner($command->owner);

        return new ListSitesView(
            sites: array_map(fn (Project $project): ListedSite => new ListedSite(
                project: $project,
                collectFullText: $collection[(string) $project->id]['collectFullText'],
                subcommandPrograms: $collection[(string) $project->id]['subcommandPrograms'],
                skillPackages: $skills[(string) $project->id]['skillPackages'] ?? null,
                needsProfile: $skills[(string) $project->id]['needsProfile'] ?? null,
                discoveryRemains: !\in_array((string) $project->id, $discoveryStarted, true),
                github: $github[(string) $project->id] ?? new GitHubSiteFacts(false, []),
            ), $projects),
        );
    }
}
