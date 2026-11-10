<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\View\GitHubPickerAccount;
use App\Module\GitHub\View\GitHubPickerRepository;
use App\Module\GitHub\View\GitHubPickerState;
use App\Module\GitHub\View\GitHubRepositoryPickerView;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Lists what the owner's own GitHub token reaches, and how each repository
 * stands for the project. Only an installation row holds a repository, so a
 * hook row of this project leaves the repository free to take over.
 */
final readonly class GitHubPickerListing
{
    public function __construct(
        private GitHubUserApi $userApi,
        private ForgeRepositoryRepository $forgeRepositories,
        private AuthorizationCheckerInterface $authorization,
        private GitHubAppConfiguration $configuration,
    ) {
    }

    /**
     * @param ?string $tick the path to tick when a free repository has it
     *
     * @throws GitHubUserApiFailed
     */
    public function list(Project $project, #[\SensitiveParameter] string $token, ?string $tick = null): GitHubRepositoryPickerView
    {
        $installations = $this->userApi->installations($token);
        usort($installations, static fn (GitHubUserInstallation $a, GitHubUserInstallation $b): int => strcasecmp($a->accountLogin, $b->accountLogin) ?: $a->id <=> $b->id);

        $lists = [];
        $ids = [];
        foreach ($installations as $installation) {
            $lists[$installation->id] = $this->userApi->installationRepositories($token, $installation->id);
            foreach ($lists[$installation->id]->repositories as $repository) {
                $ids[] = $repository->externalId();
            }
        }

        $rows = $this->forgeRepositories->findInstallationRows(GitHubDelivery::FORGE, $ids);
        $found = false;
        $accounts = [];
        foreach ($installations as $installation) {
            $list = $lists[$installation->id];
            $repositories = array_map(function ($repository) use ($rows, $project, $tick, &$found): GitHubPickerRepository {
                $row = $rows[$repository->externalId()] ?? null;
                $state = match (true) {
                    null === $row => GitHubPickerState::Free,
                    $this->isOf($row, $project) => GitHubPickerState::InProject,
                    default => GitHubPickerState::OtherProject,
                };
                $isTick = null !== $tick && 0 === strcasecmp($tick, $repository->fullName);
                $found = $found || $isTick;

                return new GitHubPickerRepository(
                    $repository,
                    $state,
                    GitHubPickerState::OtherProject === $state && null !== $row && $this->authorization->isGranted(ProjectVoter::VIEW, $row->project) ? $row->project->name : null,
                    $isTick && GitHubPickerState::Free === $state,
                );
            }, $list->repositories);
            usort($repositories, static fn (GitHubPickerRepository $a, GitHubPickerRepository $b): int => strcasecmp($a->repository->fullName, $b->repository->fullName));

            $accounts[] = new GitHubPickerAccount($installation, $repositories, $list->complete, $this->adjustUrl($installation));
        }

        return new GitHubRepositoryPickerView($project, $accounts, null !== $tick && !$found ? $tick : null);
    }

    private function adjustUrl(GitHubUserInstallation $installation): ?string
    {
        if (null !== $installation->htmlUrl) {
            return $installation->htmlUrl;
        }

        $slug = $this->configuration->slug;
        if (null === $slug || '' === $slug || null === $installation->accountId) {
            return null;
        }

        return \sprintf('https://github.com/apps/%s/installations/new/permissions?target_id=%d', rawurlencode($slug), $installation->accountId);
    }

    private function isOf(ForgeRepository $row, Project $project): bool
    {
        return null !== $project->id && true === $row->project->id?->equals($project->id);
    }
}
