<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Project\Workshop\WorkshopGitIdentity;

/** Reads the git identity that each running bridge reported for a project, and says what the owner needs to know about it. */
final readonly class GitIdentityLines
{
    public function __construct(
        private WorkshopConnectionsProviderInterface $connections,
        private GitHubUserConnectionRepository $gitHubUserConnections,
        private ConnectedAccountRepository $connectedAccounts,
        private GitHubAppConfiguration $appConfiguration,
    ) {
    }

    /** @return list<GitIdentityLine> */
    public function forProject(Project $project): array
    {
        $owner = $this->ownerGitHub($project);
        $lines = [];
        foreach ($this->connections->forProject($project) as $connection) {
            if ($connection->quiet) {
                continue;
            }

            if (null !== $connection->gitIdentity) {
                $lines[] = $this->lineOf($project, $connection, $connection->gitIdentity, $owner);
            } elseif ($project->gitIdentityManaged && !$connection->reportsGitIdentities) {
                $lines[] = new GitIdentityLine($connection->label, GitIdentityState::NotManaged, '', '', null);
            }
        }

        return $lines;
    }

    /** @param array{login: ?string, ids: list<int>} $owner */
    private function lineOf(Project $project, WorkshopConnection $connection, WorkshopGitIdentity $identity, array $owner): GitIdentityLine
    {
        $login = $identity->pushLogin;
        $state = match (true) {
            WorkshopGitIdentity::MODE_APP_TOKEN === $identity->mode => GitIdentityState::AppBot,
            WorkshopGitIdentity::MODE_AGENT_ACCOUNT === $identity->mode => '' === $login ? GitIdentityState::AgentAccountUnverified : GitIdentityState::AgentAccount,
            '' === $login => GitIdentityState::Unknown,
            $this->isLoupeAppBot($login) => GitIdentityState::AppBot,
            $this->isOwner($identity, $owner) => GitIdentityState::OwnerAccount,
            null !== $project->agentGitHubLogin && 0 === strcasecmp($login, $project->agentGitHubLogin) => GitIdentityState::Detected,
            default => GitIdentityState::MaybeOwnerAccount,
        };

        return new GitIdentityLine($connection->label, $state, $identity->commitName, $login, $identity->reportedAt);
    }

    private function isLoupeAppBot(string $login): bool
    {
        $slug = $this->appConfiguration->slug;

        return null !== $slug && '' !== $slug && 0 === strcasecmp($login, $slug.'[bot]');
    }

    /** @param array{login: ?string, ids: list<int>} $owner */
    private function isOwner(WorkshopGitIdentity $identity, array $owner): bool
    {
        return null !== $identity->githubUserId && [] !== $owner['ids']
            ? \in_array($identity->githubUserId, $owner['ids'], true)
            : null !== $owner['login'] && 0 === strcasecmp($identity->pushLogin, $owner['login']);
    }

    /** @return array{login: ?string, ids: list<int>} what Loupe holds of the GitHub account of the project owner */
    private function ownerGitHub(Project $project): array
    {
        $connection = $this->gitHubUserConnections->findSummaryByUser($project->owner);
        $ids = null === $connection ? [] : [$connection->githubUserId];
        foreach ($this->connectedAccounts->findByUser($project->owner) as $account) {
            if (SocialProvider::Github === $account->provider && ctype_digit($account->providerUserId)) {
                $ids[] = (int) $account->providerUserId;
            }
        }

        return ['login' => $connection?->login, 'ids' => array_values(array_unique($ids))];
    }
}
