<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/**
 * Asks GitHub, as the App, whether one GitHub user can still push to one
 * repository. The user id is durable and the login is not, so the login is
 * read again on each check.
 */
final readonly class GitHubRepositoryPushAccess
{
    private const array PUSHING_PERMISSIONS = ['admin', 'write'];

    public function __construct(
        private GitHubAppApi $api,
    ) {
    }

    /**
     * @param string $repositoryPath the `owner/name` of the repository
     * @param string $accountId      the GitHub user id
     *
     * @throws GitHubAppApiFailed    when GitHub mints no installation token, as a failed mint does
     * @throws GitHubGitTokenRefused `no_push_access` when the user cannot push, `github_unavailable` when GitHub does not answer
     */
    public function check(int $installationId, string $repositoryPath, string $accountId): void
    {
        if (!ctype_digit($accountId)) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::NO_PUSH_ACCESS);
        }

        // Minted apart, so that a 404 below means the user or the access is gone, and never the installation.
        $this->api->installationToken($installationId);

        try {
            $login = $this->api->get($installationId, '/user/'.$accountId)['login'] ?? null;
            if (!\is_string($login) || '' === $login) {
                throw new GitHubGitTokenRefused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
            }

            $permission = $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($repositoryPath).'/collaborators/'.rawurlencode($login).'/permission')['permission'] ?? null;
        } catch (GitHubAppApiFailed $failed) {
            throw new GitHubGitTokenRefused(404 === $failed->status ? GitHubGitTokenRefused::NO_PUSH_ACCESS : GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
        }

        if (!\in_array($permission, self::PUSHING_PERMISSIONS, true)) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::NO_PUSH_ACCESS);
        }
    }
}
