<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use App\Module\GitHub\View\GitHubInstallProjectChoice;
use App\Module\GitHub\View\GitHubInstallProjectOption;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;

final readonly class ShowGitHubInstallProjectHandler
{
    public function __construct(
        private GitHubInstallSession $installSession,
        private GitHubUserApi $userApi,
        private ProjectRepository $projects,
        private ForgeRepositoryRepository $forgeRepositories,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return ?GitHubInstallProjectChoice null when the owner has no live sign-in that waits for a project
     *
     * @throws DomainErrors with the sign-in removed, when GitHub fails or no longer lists the installation
     */
    public function __invoke(ShowGitHubInstallProjectCommand $command): ?GitHubInstallProjectChoice
    {
        $token = $this->installSession->tokenForChoice();
        $hint = $this->installSession->pending()?->installationId;
        if (null === $token || null === $hint) {
            return null;
        }

        try {
            $installation = array_find($this->userApi->installations($token), static fn ($candidate): bool => $candidate->id === $hint);
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_api_failed', ['installationId' => $hint, 'reason' => $e->reason, 'status' => $e->status]);
            $this->installSession->clear();

            throw new DomainErrors(['github' => 'github.app.flash.github_unavailable']);
        }

        if (null === $installation) {
            $this->installSession->clear();

            throw new DomainErrors(['installation' => 'github.app.flash.installation_unknown']);
        }

        $counts = [];
        foreach ($this->forgeRepositories->findByOwner($command->owner) as $repository) {
            $key = (string) $repository->project->id;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return new GitHubInstallProjectChoice(
            $installation->accountLogin,
            array_map(
                static fn ($project): GitHubInstallProjectOption => new GitHubInstallProjectOption($project, $counts[(string) $project->id] ?? 0),
                $this->projects->findByOwner($command->owner),
            ),
        );
    }
}
