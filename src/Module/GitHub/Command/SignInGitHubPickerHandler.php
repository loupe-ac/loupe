<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\GitHubRepositoryPick;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\GitHubPickerListing;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Trades the code for the owner's token and keeps it in the picker session.
 * After a fresh install, one free repository is added at once.
 */
final readonly class SignInGitHubPickerHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private AuthorizationCheckerInterface $authorization,
        private GitHubUserApi $gitHubUserApi,
        private GitHubInstallSession $installSession,
        private GitHubPickerListing $listing,
        private AddGitHubRepositoriesHandler $addRepositories,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws DomainErrors with the session cleared, so nothing is added */
    public function __invoke(SignInGitHubPickerCommand $command): GitHubPickerSignIn
    {
        $pending = $command->pending;
        $project = Uuid::isValid($pending->projectId) ? $this->projects->find(Uuid::fromString($pending->projectId)) : null;
        if (null === $project || !$this->authorization->isGranted(GitHubConnectionVoter::MANAGE, $project)) {
            throw new DomainErrors(['project' => 'github.app.flash.project_unavailable']);
        }

        try {
            $token = $this->gitHubUserApi->exchangeCode($command->code, $pending->codeVerifier ?? throw new \LogicException('A sign-in holds its verifier'), $command->redirectUri)->accessToken;
            $this->installSession->signIn($pending, $token);
            if (null === $pending->installationId) {
                return new GitHubPickerSignIn(null);
            }

            $view = $this->listing->list($project, $token);
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_api_failed', [
                'projectId' => (string) $project->id,
                'installationId' => $pending->installationId,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);
            $this->installSession->clear();

            throw new DomainErrors(['installation' => 'github.app.flash.github_unavailable']);
        }

        $account = array_find($view->accounts, static fn ($candidate): bool => $candidate->installation->id === $pending->installationId);
        if (null === $account) {
            $this->logger->info('github.installation_refused', [
                'projectId' => (string) $project->id,
                'installationId' => $pending->installationId,
                'reason' => 'not_listed_for_user',
            ]);
            $this->installSession->clear();

            throw new DomainErrors(['installation' => 'github.app.flash.installation_not_listed']);
        }

        // A cut list may hide a second free repository, so it never counts as exactly one.
        $free = $account->free();
        if (!$account->complete || 1 !== \count($free)) {
            return new GitHubPickerSignIn(null);
        }

        $repository = $free[0]->repository;
        $added = ($this->addRepositories)(new AddGitHubRepositoriesCommand($project, [new GitHubRepositoryPick($pending->installationId, $repository->id, $repository->fullName)]));

        return new GitHubPickerSignIn($added->added[0] ?? null, $added->refused);
    }
}
