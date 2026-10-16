<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\EpicBranchMergeFailed;
use App\Module\Forge\Service\EpicBranchMerger;
use App\Module\GitHub\GitHubDelivery;

/** Merges the default branch into an epic branch as the App installation that delivers the repository to the project. */
final readonly class GitHubEpicBranchMerger implements EpicBranchMerger
{
    /** An operator must change the App settings, so a retry cannot succeed. */
    private const array CONFIGURATION_REASONS = ['not_configured', 'bad_key'];

    public function __construct(
        private GitHubAppApi $api,
        private GitHubPullRequestInstallations $installations,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function merge(ForgePullRequest $reference, string $epicBranch, string $baseBranch): void
    {
        try {
            [$installationId, $path] = $this->installations->for($reference);
        } catch (GitHubInstallationUnavailable $e) {
            throw new EpicBranchMergeFailed($e->reason, permanent: true, previous: $e);
        }

        try {
            $this->api->post(
                $installationId,
                GitHubPullRequestInstallations::repositoryPath($path).'/merges',
                ['base' => $epicBranch, 'head' => $baseBranch],
                emptyBodyAccepted: true,
            );
        } catch (GitHubAppApiFailed $e) {
            // GitHub answers 404 when the repository has no epic branch, so there is nothing to merge into.
            if ('http_status' === $e->reason && 404 === $e->status && !$e->rateLimited) {
                return;
            }

            throw self::failed($e);
        }
    }

    private static function failed(GitHubAppApiFailed $e): EpicBranchMergeFailed
    {
        if ($e->rateLimited) {
            return new EpicBranchMergeFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('http_status' === $e->reason && \in_array($e->status, [401, 403], true)) {
            return new EpicBranchMergeFailed('permission', permanent: true, previous: $e);
        }
        if ('http_status' === $e->reason && 409 === $e->status) {
            return new EpicBranchMergeFailed('merge-conflict', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new EpicBranchMergeFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
