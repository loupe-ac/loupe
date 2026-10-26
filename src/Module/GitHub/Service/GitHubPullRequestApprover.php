<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestApproval;
use App\Module\Forge\Service\PullRequestApprover;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\GitHubDelivery;

/** Approves a pull request as the App installation that delivers its repository to the project, and dismisses that approval. */
final readonly class GitHubPullRequestApprover implements PullRequestApprover
{
    private const string DISMISS = 'mutation($id:ID!,$message:String!){dismissPullRequestReview(input:{pullRequestReviewId:$id,message:$message}){pullRequestReview{state}}}';
    private const string STATE = 'query($id:ID!){node(id:$id){... on PullRequestReview{state}}}';

    private const array REFUSED_STATUSES = [401, 403, 404, 422];

    /** An operator must change the App settings, so a retry cannot succeed. */
    private const array CONFIGURATION_REASONS = ['not_configured', 'bad_key'];

    /** GitHub sends a GraphQL rate limit as an HTTP 200 error body, so the API client reads no delay for it. */
    private const int GRAPHQL_RATE_LIMIT_RETRY_SECONDS = 60;

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
    public function canApprove(ForgePullRequest $pullRequest): bool
    {
        try {
            $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable) {
            return false;
        }

        return true;
    }

    #[\Override]
    public function approve(ForgePullRequest $pullRequest, string $headSha, string $body): PullRequestApproval
    {
        [$installationId, $path] = $this->installation($pullRequest);

        try {
            $review = $this->api->post(
                $installationId,
                GitHubPullRequestInstallations::repositoryPath($path).'/pulls/'.$pullRequest->number.'/reviews',
                ['event' => 'APPROVE', 'commit_id' => $headSha, 'body' => $body],
            );
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }

        $id = $review['node_id'] ?? null;
        $commit = $review['commit_id'] ?? null;
        if (!\is_string($id) || '' === $id || !\is_string($commit) || '' === $commit) {
            throw new PullRequestWriteFailed('malformed_body', permanent: false);
        }

        return new PullRequestApproval($id, $commit);
    }

    #[\Override]
    public function dismiss(ForgePullRequest $pullRequest, string $reviewId, string $message): void
    {
        [$installationId] = $this->installation($pullRequest);

        try {
            $this->api->graphql($installationId, self::DISMISS, ['id' => $reviewId, 'message' => $message]);
        } catch (GitHubAppApiFailed $e) {
            // GitHub refuses a dismissal of a review that is not standing. That state is the goal.
            if ('graphql_error' === $e->reason && 'UNPROCESSABLE' === $e->graphqlType && !$this->isApproved($installationId, $reviewId)) {
                return;
            }

            throw self::failed($e);
        }
    }

    /**
     * @return array{int, string} the installation id, and the repository path as the forge spells it
     *
     * @throws PullRequestWriteFailed
     */
    private function installation(ForgePullRequest $pullRequest): array
    {
        try {
            return $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestWriteFailed($e->reason, permanent: true, previous: $e);
        }
    }

    private function isApproved(int $installationId, string $reviewId): bool
    {
        try {
            $data = $this->api->graphql($installationId, self::STATE, ['id' => $reviewId]);
        } catch (GitHubAppApiFailed) {
            return true;
        }

        return 'APPROVED' === ($data['node']['state'] ?? 'APPROVED');
    }

    private static function failed(GitHubAppApiFailed $e): PullRequestWriteFailed
    {
        if ($e->rateLimited) {
            return new PullRequestWriteFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('graphql_error' === $e->reason && 'RATE_LIMITED' === $e->graphqlType) {
            return new PullRequestWriteFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: self::GRAPHQL_RATE_LIMIT_RETRY_SECONDS);
        }
        if (('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true))
            || ('graphql_error' === $e->reason && \in_array($e->graphqlType, ['FORBIDDEN', 'UNPROCESSABLE'], true))) {
            return new PullRequestWriteFailed('permission', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestWriteFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
