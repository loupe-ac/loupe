<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Module\GitHub\GitHubRepositoryPick;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\GitHubPickerListing;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Binds the project that the owner chose, or creates it, to the sign-in that began on github.com. When the
 * installation offers exactly one free repository, the handler adds it at once, as the sign-in does after a fresh
 * install from Loupe. Otherwise the picker opens on the project.
 */
final readonly class ChooseGitHubInstallProjectHandler
{
    public function __construct(
        private GitHubInstallSession $installSession,
        private ProjectRepository $projects,
        private AuthorizationCheckerInterface $authorization,
        private CreateProjectHandler $createProject,
        private GitHubPickerListing $listing,
        private AddGitHubRepositoriesHandler $addRepositories,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws DomainErrors */
    public function __invoke(ChooseGitHubInstallProjectCommand $command): ChosenGitHubInstallProject
    {
        $pending = $this->installSession->pending();
        $token = $this->installSession->tokenForChoice();
        if (null === $pending || null === $token || null === $pending->installationId) {
            throw new DomainErrors(['token' => 'github.picker.flash.expired']);
        }

        if (null !== $command->projectId) {
            $project = Uuid::isValid($command->projectId) ? $this->projects->find(Uuid::fromString($command->projectId)) : null;
            if (null === $project || !$this->authorization->isGranted(GitHubConnectionVoter::MANAGE, $project)) {
                throw new DomainErrors(['project' => 'github.app.flash.project_unavailable']);
            }
        } else {
            $name = trim($command->name ?? '');
            if ('' === $name) {
                throw new DomainErrors(['name' => 'github.install_project.error.name_required']);
            }

            $project = ($this->createProject)(new CreateProjectCommand($command->owner, $name, null, SearchLanguage::DEFAULT));
        }

        $this->installSession->bindProject($pending, $project);

        try {
            return $this->open($project, $token, $pending->installationId);
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_api_failed', [
                'projectId' => (string) $project->id,
                'installationId' => $pending->installationId,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);
            $this->installSession->clear();

            throw new DomainErrors(['github' => 'github.app.flash.github_unavailable']);
        }
    }

    /** @throws GitHubUserApiFailed */
    private function open(Project $project, #[\SensitiveParameter] string $token, int $installationId): ChosenGitHubInstallProject
    {
        $view = $this->listing->list($project, $token, null, $installationId);
        $account = array_find($view->accounts, static fn ($candidate): bool => $candidate->installation->id === $installationId);
        // A cut list may hide a second free repository, so it never counts as exactly one.
        $free = null === $account ? [] : $account->free();
        if (null === $account || !$account->complete || 1 !== \count($free)) {
            return new ChosenGitHubInstallProject($project, null);
        }

        $repository = $free[0]->repository;
        $added = ($this->addRepositories)(new AddGitHubRepositoriesCommand($project, [new GitHubRepositoryPick($installationId, $repository->id, $repository->fullName)]));

        return new ChosenGitHubInstallProject($project, $added->added[0] ?? null, $added->refused);
    }
}
