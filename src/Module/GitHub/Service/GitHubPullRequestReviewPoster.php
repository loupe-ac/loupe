<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PostedPullRequestReview;
use App\Module\Forge\Service\PullRequestReviewFailed;
use App\Module\Forge\Service\PullRequestReviewKind;
use App\Module\Forge\Service\PullRequestReviewPoster;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use Symfony\Component\Uid\Uuid;

/** Posts a review as the GitHub account that a user connected, never as the App. */
final readonly class GitHubPullRequestReviewPoster implements PullRequestReviewPoster
{
    private const array REFUSED_STATUSES = [401, 403, 404, 422];

    /** GitHub caps a review body at 65536 characters. */
    private const int MAX_BODY_LENGTH = 60000;

    public function __construct(
        private UserRepository $users,
        private GitHubUserTokenRefresher $tokens,
        private GitHubUserApi $api,
        private GitHubUserConnectionRepository $gitHubUserConnections,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function post(ForgePullRequest $pullRequest, PullRequestReviewKind $kind, string $body, string $userId): ?string
    {
        $user = $this->user($userId);
        if ('' === trim($body) && PullRequestReviewKind::Approve !== $kind) {
            throw new PullRequestReviewFailed('empty_body', permanent: true);
        }
        $accessToken = $this->accessToken($user);

        $event = match ($kind) {
            PullRequestReviewKind::Approve => 'APPROVE',
            PullRequestReviewKind::RequestChanges => 'REQUEST_CHANGES',
            PullRequestReviewKind::Comment => 'COMMENT',
        };

        try {
            return $this->api->postReview(
                $accessToken,
                GitHubPullRequestInstallations::repositoryPath($pullRequest->repository),
                $pullRequest->number,
                $event,
                mb_substr($body, 0, self::MAX_BODY_LENGTH),
            );
        } catch (GitHubUserApiFailed $e) {
            throw self::failed($e);
        }
    }

    #[\Override]
    public function findPosted(ForgePullRequest $pullRequest, string $marker, string $userId): ?PostedPullRequestReview
    {
        $user = $this->user($userId);
        $accessToken = $this->accessToken($user);
        $connection = $this->gitHubUserConnections->findOneByUser($user)
            ?? throw new PullRequestReviewFailed('not_connected', permanent: true);

        try {
            $reviews = $this->api->reviews($accessToken, GitHubPullRequestInstallations::repositoryPath($pullRequest->repository), $pullRequest->number);
        } catch (GitHubUserApiFailed $e) {
            throw self::failed($e);
        }

        foreach ($reviews as $review) {
            if ($connection->githubUserId === $review['userId'] && str_contains($review['body'], $marker)) {
                return new PostedPullRequestReview($review['url']);
            }
        }

        return null;
    }

    /** @throws PullRequestReviewFailed */
    private function user(string $userId): User
    {
        return (Uuid::isValid($userId) ? $this->users->find(Uuid::fromString($userId)) : null)
            ?? throw new PullRequestReviewFailed('not_connected', permanent: true);
    }

    /**
     * @return non-empty-string
     *
     * @throws PullRequestReviewFailed
     */
    private function accessToken(User $user): string
    {
        $token = $this->tokens->accessTokenFor($user);
        $accessToken = $token->accessToken;
        if (GitHubUserTokenStatus::Fresh !== $token->status || null === $accessToken) {
            throw match ($token->status) {
                GitHubUserTokenStatus::Expired => new PullRequestReviewFailed('connection_expired', permanent: true),
                GitHubUserTokenStatus::Unavailable => new PullRequestReviewFailed('token_unavailable', permanent: false),
                default => new PullRequestReviewFailed('not_connected', permanent: true),
            };
        }

        return $accessToken;
    }

    private static function failed(GitHubUserApiFailed $e): PullRequestReviewFailed
    {
        if ($e->rateLimited) {
            return new PullRequestReviewFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
            return new PullRequestReviewFailed('permission', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestReviewFailed('api_failed_'.$cause, permanent: false, previous: $e);
    }
}
