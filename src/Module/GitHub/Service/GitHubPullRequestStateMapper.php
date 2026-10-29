<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Service\PullRequestUnreadable;

/** Turns the GraphQL `pullRequest` node of GitHubPullRequestStateReader into a snapshot. */
final readonly class GitHubPullRequestStateMapper
{
    private const array MERGEABLE_STATES = ['CLEAN', 'HAS_HOOKS', 'UNSTABLE'];

    public function __construct(
        private GitHubAppConfiguration $app,
    ) {
    }

    /**
     * @param array<mixed> $pullRequest
     * @param ?int         $behindBy    the commits the head lacks from the base, or null when not compared
     *
     * @throws PullRequestUnreadable
     */
    public function map(array $pullRequest, ?GitHubBranchRules $rules, ?int $behindBy): PullRequestSnapshot
    {
        $state = match ($pullRequest['state'] ?? null) {
            'OPEN' => PullRequestState::Open,
            'MERGED' => PullRequestState::Merged,
            'CLOSED' => PullRequestState::Closed,
            default => null,
        };
        $headSha = $pullRequest['headRefOid'] ?? null;
        $baseBranch = $pullRequest['baseRefName'] ?? null;
        if (null === $state || !\is_string($headSha) || '' === $headSha || !\is_string($baseBranch) || '' === $baseBranch) {
            throw new PullRequestUnreadable('malformed_body');
        }

        $draft = true === ($pullRequest['isDraft'] ?? null);
        $mergeable = $pullRequest['mergeable'] ?? null;
        $mergeStateStatus = $pullRequest['mergeStateStatus'] ?? null;
        [$approvedAt, $approvalSha, $approvalId] = $this->approval($pullRequest);
        [$latestApprovedAt, $latestApprovalId, $latestApproverLogin] = $this->latestApproval($pullRequest);
        [$checks, $failedChecks] = $this->checks($pullRequest, $rules, 'BLOCKED' === $mergeStateStatus);
        $mergeability = match (true) {
            'CONFLICTING' === $mergeable, 'DIRTY' === $mergeStateStatus => PullRequestMergeability::Conflicting,
            // GitHub answers UNKNOWN for a few seconds after the base moves, and only the retry of an unknown read learns of a conflict.
            'UNKNOWN' === $mergeable => PullRequestMergeability::Unknown,
            'BEHIND' === $mergeStateStatus, $behindBy > 0 => PullRequestMergeability::Behind,
            'BLOCKED' === $mergeStateStatus => PullRequestMergeability::Blocked,
            \in_array($mergeStateStatus, self::MERGEABLE_STATES, true) => PullRequestMergeability::Mergeable,
            default => PullRequestMergeability::Unknown,
        };

        return new PullRequestSnapshot(
            state: $state,
            draft: $draft,
            headSha: $headSha,
            baseBranch: $baseBranch,
            checks: $checks,
            checksSha: PullRequestChecks::Pending === $checks ? null : $headSha,
            failedChecks: $failedChecks,
            mergeability: $mergeability,
            review: $this->review($pullRequest),
            readyToMerge: PullRequestState::Open === $state && !$draft && PullRequestChecks::Passed === $checks && PullRequestMergeability::Mergeable === $mergeability,
            changesRequestedAt: $this->changesRequestedAt($pullRequest),
            openedAt: $this->time($pullRequest['createdAt'] ?? null),
            mergedAt: $this->time($pullRequest['mergedAt'] ?? null),
            approvedAt: $approvedAt,
            approvalSha: $approvalSha,
            defaultBranch: $this->defaultBranch($pullRequest),
            headParents: $this->headParents($pullRequest),
            approvalId: $approvalId,
            headBranch: $this->headBranch($pullRequest),
            authorId: $this->authorId($pullRequest),
            authorLogin: $this->authorLogin($pullRequest),
            latestApprovedAt: $latestApprovedAt,
            latestApprovalId: $latestApprovalId,
            latestApproverLogin: $latestApproverLogin,
            title: $this->title($pullRequest),
            closedAt: $this->time($pullRequest['closedAt'] ?? null),
        );
    }

    /**
     * A missing head branch is null, because no read decision rests on it.
     *
     * @param array<mixed> $pullRequest
     */
    private function headBranch(array $pullRequest): ?string
    {
        $name = $pullRequest['headRefName'] ?? null;

        return \is_string($name) && '' !== $name ? $name : null;
    }

    /**
     * Only a user account has an id. A bot or a deleted account reads null.
     *
     * @param array<mixed> $pullRequest
     */
    private function authorId(array $pullRequest): ?string
    {
        $id = $pullRequest['author']['databaseId'] ?? null;

        return \is_int($id) && $id > 0 ? (string) $id : null;
    }

    /**
     * A missing title is null, because no read decision rests on it.
     *
     * @param array<mixed> $pullRequest
     */
    private function title(array $pullRequest): ?string
    {
        $title = $pullRequest['title'] ?? null;

        return \is_string($title) && '' !== $title ? $title : null;
    }

    /** @param array<mixed> $pullRequest */
    private function authorLogin(array $pullRequest): ?string
    {
        $login = $pullRequest['author']['login'] ?? null;

        return \is_string($login) && '' !== $login ? $login : null;
    }

    /**
     * The time, commit and review id of the oldest approval that stands.
     *
     * @param array<mixed> $pullRequest
     *
     * @return array{?\DateTimeImmutable, ?string, ?string}
     */
    private function approval(array $pullRequest): array
    {
        $nodes = $pullRequest['latestOpinionatedReviews']['nodes'] ?? [];
        $oldest = [null, null, null];
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            if (!\is_array($node) || 'APPROVED' !== ($node['state'] ?? null) || $this->isAppReview($node)) {
                continue;
            }
            $time = $this->time($node['submittedAt'] ?? null);
            $oid = $node['commit']['oid'] ?? null;
            $id = $node['id'] ?? null;
            if (null !== $time && \is_string($oid) && '' !== $oid && \is_string($id) && '' !== $id && (null === $oldest[0] || $time < $oldest[0])) {
                $oldest = [$time, $oid, $id];
            }
        }

        return $oldest;
    }

    /**
     * The time, review id and login of the newest approval that stands. GitHub shows a deleted account as `ghost`, so the login is never empty.
     *
     * @param array<mixed> $pullRequest
     *
     * @return array{?\DateTimeImmutable, ?string, ?string}
     */
    private function latestApproval(array $pullRequest): array
    {
        $nodes = $pullRequest['latestOpinionatedReviews']['nodes'] ?? [];
        $newest = [null, null, null];
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            if (!\is_array($node) || 'APPROVED' !== ($node['state'] ?? null) || $this->isAppReview($node)) {
                continue;
            }
            $time = $this->time($node['submittedAt'] ?? null);
            $id = $node['id'] ?? null;
            $login = $node['author']['login'] ?? null;
            if (null !== $time && \is_string($id) && '' !== $id && (null === $newest[0] || $time > $newest[0])) {
                $newest = [$time, $id, \is_string($login) && '' !== $login ? $login : 'ghost'];
            }
        }

        return $newest;
    }

    /**
     * GraphQL gives the App's login without the `[bot]` suffix that REST adds, so both spellings count.
     *
     * @param array<mixed> $review
     */
    private function isAppReview(array $review): bool
    {
        $slug = $this->app->slug;
        $login = $review['author']['login'] ?? null;

        return null !== $slug && '' !== $slug
            && 'Bot' === ($review['author']['__typename'] ?? null)
            && \is_string($login)
            && \in_array(mb_strtolower($login), [mb_strtolower($slug), mb_strtolower($slug).'[bot]'], true);
    }

    /** @param array<mixed> $pullRequest */
    private function defaultBranch(array $pullRequest): ?string
    {
        $name = $pullRequest['baseRepository']['defaultBranchRef']['name'] ?? null;

        return \is_string($name) && '' !== $name ? $name : null;
    }

    /**
     * @param array<mixed> $pullRequest
     *
     * @return list<string>
     */
    private function headParents(array $pullRequest): array
    {
        $nodes = $pullRequest['commits']['nodes'][0]['commit']['parents']['nodes'] ?? [];
        $oids = [];
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            $oid = \is_array($node) ? ($node['oid'] ?? null) : null;
            if (\is_string($oid) && '' !== $oid) {
                $oids[] = $oid;
            }
        }

        return $oids;
    }

    /** A malformed time is null, because the times feed reports only and must not make the read unreadable. */
    private function time(mixed $value): ?\DateTimeImmutable
    {
        $time = \is_string($value) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value) : false;

        return false === $time ? null : $time->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * GitHub gives no review decision on a repository without a required
     * review, so the latest review of each writer decides there.
     *
     * @param array<mixed> $pullRequest
     */
    private function review(array $pullRequest): PullRequestReview
    {
        $decision = $pullRequest['reviewDecision'] ?? null;
        if (null !== $decision) {
            return match ($decision) {
                'APPROVED' => PullRequestReview::Approved,
                'CHANGES_REQUESTED' => PullRequestReview::ChangesRequested,
                'REVIEW_REQUIRED' => PullRequestReview::Required,
                default => PullRequestReview::None,
            };
        }

        $nodes = $pullRequest['latestOpinionatedReviews']['nodes'] ?? [];
        $states = \is_array($nodes) ? array_map(static fn (mixed $node): mixed => \is_array($node) ? ($node['state'] ?? null) : null, $nodes) : [];

        return match (true) {
            \in_array('CHANGES_REQUESTED', $states, true) => PullRequestReview::ChangesRequested,
            \in_array('APPROVED', $states, true) => PullRequestReview::Approved,
            default => PullRequestReview::None,
        };
    }

    /**
     * The time of the newest request for changes among the latest opinionated reviews.
     *
     * @param array<mixed> $pullRequest
     */
    private function changesRequestedAt(array $pullRequest): ?\DateTimeImmutable
    {
        $nodes = $pullRequest['latestOpinionatedReviews']['nodes'] ?? [];
        $newest = null;
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            if (!\is_array($node) || 'CHANGES_REQUESTED' !== ($node['state'] ?? null)) {
                continue;
            }
            $time = $this->time($node['submittedAt'] ?? null);
            if (null !== $time && (null === $newest || $time > $newest)) {
                $newest = $time;
            }
        }

        return $newest;
    }

    /**
     * The approval check of Loupe never counts, because the gate waits on it and it would wait on itself.
     * When it is required, it alone can explain a blocked merge, so a blocked merge proves no other check pending.
     * A check is required when the ruleset names it or the head flags it. The
     * ruleset finds a check that never ran, and the flag finds one that classic
     * branch protection requires.
     *
     * @param array<mixed> $pullRequest
     *
     * @return array{PullRequestChecks, list<string>}
     */
    private function checks(array $pullRequest, ?GitHubBranchRules $rules, bool $blocked): array
    {
        $verdicts = [];
        $flags = [];
        $approvalRequired = \in_array(ForgePullRequest::APPROVAL_CHECK_NAME, $rules->requiredChecks ?? [], true);
        foreach ($this->contexts($pullRequest) as $context) {
            [$type, $name, $verdict] = match ($context['__typename'] ?? null) {
                'CheckRun' => ['CheckRun', $context['name'] ?? null, $this->checkRunVerdict($context)],
                'StatusContext' => ['StatusContext', $context['context'] ?? null, $this->statusVerdict($context)],
                default => [null, null, null],
            };
            if (ForgePullRequest::APPROVAL_CHECK_NAME === $name) {
                $approvalRequired = $approvalRequired || true === ($context['isRequired'] ?? null);

                continue;
            }
            if (null === $type || !\is_string($name) || '' === $name || null === $verdict) {
                continue;
            }

            // A check run and a commit status can share a name, and each must pass.
            $verdicts[$name][$type] = $verdict;
            $flags[$name][$type] = true === ($context['isRequired'] ?? null);
        }

        $flaggedRequired = array_keys(array_filter($flags, static fn (array $byType): bool => \in_array(true, $byType, true)));
        $required = array_values(array_diff(array_unique([...$rules->requiredChecks ?? [], ...array_map(strval(...), $flaggedRequired)]), [ForgePullRequest::APPROVAL_CHECK_NAME]));
        if ([] === $required) {
            return [$blocked && !$approvalRequired ? PullRequestChecks::Pending : PullRequestChecks::Passed, []];
        }

        $failed = [];
        foreach ($required as $name) {
            $named = $verdicts[$name] ?? [PullRequestChecks::Pending];
            if (\in_array(PullRequestChecks::Pending, $named, true)) {
                return [PullRequestChecks::Pending, []];
            }
            if (\in_array(PullRequestChecks::Failed, $named, true)) {
                $failed[] = $name;
            }
        }
        sort($failed);

        return [[] === $failed ? PullRequestChecks::Passed : PullRequestChecks::Failed, $failed];
    }

    /**
     * @param array<mixed> $pullRequest
     *
     * @return list<array<mixed>>
     */
    private function contexts(array $pullRequest): array
    {
        $nodes = $pullRequest['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['nodes'] ?? [];

        return \is_array($nodes) ? array_values(array_filter($nodes, \is_array(...))) : [];
    }

    /** @param array<mixed> $checkRun */
    private function checkRunVerdict(array $checkRun): PullRequestChecks
    {
        if ('COMPLETED' !== ($checkRun['status'] ?? null)) {
            return PullRequestChecks::Pending;
        }

        return \in_array($checkRun['conclusion'] ?? null, ['SUCCESS', 'NEUTRAL', 'SKIPPED'], true) ? PullRequestChecks::Passed : PullRequestChecks::Failed;
    }

    /**
     * A state GitHub has not documented stays pending rather than reads as a failure.
     *
     * @param array<mixed> $status
     */
    private function statusVerdict(array $status): PullRequestChecks
    {
        return match ($status['state'] ?? null) {
            'SUCCESS' => PullRequestChecks::Passed,
            'FAILURE', 'ERROR' => PullRequestChecks::Failed,
            default => PullRequestChecks::Pending,
        };
    }
}
