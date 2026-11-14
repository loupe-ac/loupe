<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\InitPullRequestReaders;
use App\Module\Forge\Service\MergeApprovals;
use App\Module\Project\Contract\ProjectMilestones;
use App\Module\Project\Repository\ProjectMilestoneRepository;

/** Reads the facts of the setup page and picks its state. */
final readonly class ShowProjectSetupHandler
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private InitRuns $initRuns,
        private ProjectMilestoneRepository $projectMilestones,
        private MergeApprovals $mergeApprovals,
        private InitPullRequestReaders $initPullRequests,
    ) {
    }

    public function __invoke(ShowProjectSetupCommand $command): ProjectSetupView
    {
        $project = $command->project;
        $projectId = $project->requireId();
        $repositories = $this->forgeRepositories->findByProject($project);
        $initRunning = $this->initRuns->isOpen($projectId);
        $initFinished = $this->projectMilestones->hasReached($project, ProjectMilestones::INIT_FINISHED);
        $bridgeConnected = $project->setUp || $this->projectMilestones->hasReached($project, ProjectMilestones::BRIDGE_CONNECTED);

        $state = match (true) {
            $project->setUp => ProjectSetupState::Done,
            !$command->mayManage => ProjectSetupState::Member,
            $initRunning => ProjectSetupState::InitRunning,
            $initFinished => ProjectSetupState::BridgePending,
            $this->initRuns->hasClosed($projectId) => ProjectSetupState::InitStopped,
            [] !== $repositories => ProjectSetupState::RepositoriesJoined,
            default => ProjectSetupState::NoRepository,
        };

        return new ProjectSetupView(
            $project,
            $state,
            array_map(static fn (ForgeRepository $repository): string => $repository->path, $repositories),
            $initRunning,
            $initFinished || $project->setUp,
            $bridgeConnected,
            $this->listed($state) ? array_map(fn (ForgeRepository $repository): SetupMergeLine => new SetupMergeLine($repository->path, $this->mergeStatus($repository)), $repositories) : [],
        );
    }

    /** The two first states show no list, so they skip the forge reads of the merge lines. */
    private function listed(ProjectSetupState $state): bool
    {
        return ProjectSetupState::NoRepository !== $state && ProjectSetupState::RepositoriesJoined !== $state;
    }

    private function mergeStatus(ForgeRepository $repository): SetupMergeStatus
    {
        $approval = $this->mergeApprovals->of($repository);
        if ($approval->declared && !$approval->unset) {
            return $approval->person ? SetupMergeStatus::Person : SetupMergeStatus::Agent;
        }

        if ($this->initPullRequests->for($repository->forge)?->setsMergeSetting($repository) ?? false) {
            return SetupMergeStatus::InInitPullRequest;
        }

        return SetupMergeStatus::NotAsked;
    }
}
