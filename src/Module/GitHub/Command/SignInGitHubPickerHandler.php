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
 * After a fresh install, one free repository is added at once. A sign-in that
 * started on github.com has no project: it keeps the session only when the
 * owner's own installation list holds the installation that GitHub named.
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
        private StartGitHubAppInstallHandler $startInstall,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws DomainErrors with the session cleared, so nothing is added */
    public function __invoke(SignInGitHubPickerCommand $command): GitHubPickerSignIn
    {
        $pending = $command->pending;
        if (null === $pending->projectId) {
            return $this->signInForChoice($command);
        }

        $project = Uuid::isValid($pending->projectId) ? $this->projects->find(Uuid::fromString($pending->projectId)) : null;
        if (null === $project || !$this->authorization->isGranted(GitHubConnectionVoter::MANAGE, $project)) {
            throw new DomainErrors(['project' => 'github.app.flash.project_unavailable']);
        }

        try {
            $token = $this->gitHubUserApi->exchangeCode($command->code, $pending->codeVerifier ?? throw new \LogicException('A sign-in holds its verifier'), $command->redirectUri)->accessToken;
            $this->installSession->signIn($pending, $token);
            if (null === $pending->installationId) {
                if (null !== $pending->repository && null !== $this->listing->list($project, $token, $pending->repository)->missingRepository) {
                    return new GitHubPickerSignIn(null, [], ($this->startInstall)(new StartGitHubAppInstallCommand($project, $pending->repository, $pending->startedFrom, $pending->init)));
                }

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

    /** @throws DomainErrors with the session cleared, when GitHub lists no such installation for the owner */
    private function signInForChoice(SignInGitHubPickerCommand $command): GitHubPickerSignIn
    {
        $pending = $command->pending;
        $hint = $pending->installationId;

        try {
            $token = $this->gitHubUserApi->exchangeCode($command->code, $pending->codeVerifier ?? throw new \LogicException('A sign-in holds its verifier'), $command->redirectUri)->accessToken;
            $listed = null === $hint ? [] : $this->gitHubUserApi->installations($token);
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_api_failed', [
                'installationId' => $hint,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);
            $this->installSession->clear();

            throw new DomainErrors(['installation' => 'github.app.flash.github_unavailable']);
        }

        if (null === $hint || !array_any($listed, static fn ($installation): bool => $installation->id === $hint)) {
            $this->logger->info('github.installation_refused', [
                'installationId' => $hint,
                'reason' => 'not_listed_for_user',
            ]);
            $this->installSession->clear();

            throw new DomainErrors(['installation' => 'github.app.flash.installation_unknown']);
        }

        $this->installSession->signIn($pending, $token);

        return new GitHubPickerSignIn(null, choose: true);
    }
}
