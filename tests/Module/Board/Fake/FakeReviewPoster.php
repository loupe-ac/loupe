<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Fake;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PostedPullRequestReview;
use App\Module\Forge\Service\PullRequestReviewKind;
use App\Module\Forge\Service\PullRequestReviewPoster;

/** Records each review posted to a `github` pull request, and throws the failure it holds. */
final class FakeReviewPoster implements PullRequestReviewPoster
{
    /** @var list<array{number: int, kind: PullRequestReviewKind, body: string, userId: string}> */
    public array $posts = [];

    /** @var list<array{number: int, userId: string, body: string, url: ?string}> the reviews the forge holds */
    public array $reviews = [];

    public ?\Throwable $failure = null;

    /** The forge keeps the review that it answers with the failure, as when the answer is lost. */
    public bool $failAfterPosting = false;

    public ?\Throwable $lookupFailure = null;

    public int $lookups = 0;

    public bool $withoutUrl = false;

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function post(ForgePullRequest $pullRequest, PullRequestReviewKind $kind, string $body, string $userId): ?string
    {
        $this->posts[] = ['number' => $pullRequest->number, 'kind' => $kind, 'body' => $body, 'userId' => $userId];
        $url = $this->withoutUrl ? null : 'https://github.com/acme/widgets/pull/'.$pullRequest->number.'#pullrequestreview-'.\count($this->posts);
        if (null === $this->failure || $this->failAfterPosting) {
            $this->reviews[] = ['number' => $pullRequest->number, 'userId' => $userId, 'body' => $body, 'url' => $url];
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $url;
    }

    #[\Override]
    public function findPosted(ForgePullRequest $pullRequest, string $marker, string $userId): ?PostedPullRequestReview
    {
        ++$this->lookups;
        if (null !== $this->lookupFailure) {
            throw $this->lookupFailure;
        }
        foreach ($this->reviews as $review) {
            if ($review['number'] === $pullRequest->number && $review['userId'] === $userId && str_contains($review['body'], $marker)) {
                return new PostedPullRequestReview($review['url']);
            }
        }

        return null;
    }
}
