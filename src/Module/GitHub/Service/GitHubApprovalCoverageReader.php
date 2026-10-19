<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\ApprovalCoverage;
use App\Module\Forge\Service\ApprovalCoverageReader;
use App\Module\GitHub\GitHubDelivery;
use App\Module\Workflow\Contract\ApprovalRules;
use Psr\Log\LoggerInterface;

/**
 * The approval covers the head when every commit since the covered head that
 * is not on the base is a merge that brings in a commit of the base. When the
 * template of the project says an approval does not cover a conflict
 * resolution, such a merge must also equal what git writes with no human edit.
 */
final readonly class GitHubApprovalCoverageReader implements ApprovalCoverageReader
{
    public function __construct(
        private GitHubAppApi $api,
        private GitHubPullRequestInstallations $installations,
        private LoggerInterface $logger,
        private ApprovalRules $approvalRules,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    /** The compare API lists at most this many files, so a list this long may be cut. */
    private const int COMPARE_FILE_LIMIT = 300;

    #[\Override]
    public function read(ForgePullRequest $pullRequest): ApprovalCoverage
    {
        $base = $pullRequest->baseBranch;
        $head = $pullRequest->headSha;
        $covered = $pullRequest->coveredSha;
        if (null === $base || '' === $base || null === $head || '' === $head || null === $covered || '' === $covered) {
            return ApprovalCoverage::Unknown;
        }

        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
            $sinceApproval = $this->compare($installationId, $path, $covered, $head);
            // A rebase or an amend leaves the covered head off the new history.
            if (\in_array($sinceApproval['status'], ['diverged', 'behind'], true) || !$sinceApproval['complete']) {
                return ApprovalCoverage::NotCovered;
            }
            if ('identical' === $sinceApproval['status'] || [] === $sinceApproval['commits']) {
                return ApprovalCoverage::Covered;
            }

            $againstBase = $this->compare($installationId, $path, $base, $head);
            if (!$againstBase['complete']) {
                return ApprovalCoverage::NotCovered;
            }

            $strict = !$this->approvalRules->coversConflictResolution($pullRequest->project->id ?? throw new \LogicException('The project is not persisted.'));
            $offBase = array_flip(array_column($againstBase['commits'], 'sha'));
            foreach ($sinceApproval['commits'] as $commit) {
                if (!isset($offBase[$commit['sha']])) {
                    continue;
                }
                $fromBase = array_values(array_filter($commit['parents'], static fn (string $parent): bool => !isset($offBase[$parent])));
                if (\count($commit['parents']) < 2 || [] === $fromBase) {
                    return ApprovalCoverage::NotCovered;
                }
                if ($strict && !$this->isMachineMerge($installationId, $path, $commit, $fromBase[0])) {
                    return ApprovalCoverage::NotCovered;
                }
            }
        } catch (GitHubInstallationUnavailable|GitHubAppApiFailed $e) {
            return $this->unknown($pullRequest, $e->reason);
        } catch (\UnexpectedValueException) {
            return $this->unknown($pullRequest, 'malformed_body');
        }

        return ApprovalCoverage::Covered;
    }

    /**
     * A merge is a machine merge when the two sides changed no common file, and the merge differs from each side
     * only in files that the other side changed. A conflict resolution or a hand edit breaks one of the three tests.
     *
     * @param array{sha: string, parents: list<string>} $merge
     *
     * @throws GitHubAppApiFailed
     * @throws \UnexpectedValueException
     */
    private function isMachineMerge(int $installationId, string $path, array $merge, string $baseSide): bool
    {
        $headSides = array_values(array_diff($merge['parents'], [$baseSide]));
        if (2 !== \count($merge['parents']) || 1 !== \count($headSides)) {
            return false;
        }
        $headSide = $headSides[0];

        $mergeBase = $this->compareFiles($installationId, $path, $baseSide, $headSide)['mergeBase'];
        $headChanged = $this->compareFiles($installationId, $path, $mergeBase, $headSide)['files'];
        $baseChanged = $this->compareFiles($installationId, $path, $mergeBase, $baseSide)['files'];
        $againstHead = $this->compareFiles($installationId, $path, $headSide, $merge['sha'])['files'];
        $againstBase = $this->compareFiles($installationId, $path, $baseSide, $merge['sha'])['files'];
        if (null === $headChanged || null === $baseChanged || null === $againstHead || null === $againstBase) {
            return false;
        }

        return [] === array_intersect($headChanged, $baseChanged)
            && [] === array_diff($againstHead, $baseChanged)
            && [] === array_diff($againstBase, $headChanged);
    }

    /**
     * @return array{mergeBase: string, files: ?list<string>} files is null when the list may be cut
     *
     * @throws GitHubAppApiFailed
     * @throws \UnexpectedValueException
     */
    private function compareFiles(int $installationId, string $path, string $from, string $to): array
    {
        $body = $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($path).'/compare/'.rawurlencode($from).'...'.rawurlencode($to));
        $mergeBase = \is_array($body['merge_base_commit'] ?? null) ? ($body['merge_base_commit']['sha'] ?? null) : null;
        $items = $body['files'] ?? [];
        if (!\is_string($mergeBase) || !\is_array($items) || !array_is_list($items)) {
            throw new \UnexpectedValueException('The compare answer has no merge base or file list.');
        }

        $files = [];
        foreach ($items as $item) {
            $name = \is_array($item) ? ($item['filename'] ?? null) : null;
            $previous = \is_array($item) ? ($item['previous_filename'] ?? null) : null;
            if (!\is_string($name) || (null !== $previous && !\is_string($previous))) {
                throw new \UnexpectedValueException('A compared file has no name.');
            }
            $files[] = $name;
            if (null !== $previous) {
                $files[] = $previous;
            }
        }

        return ['mergeBase' => $mergeBase, 'files' => \count($items) >= self::COMPARE_FILE_LIMIT ? null : array_values(array_unique($files))];
    }

    /**
     * @return array{status: string, complete: bool, commits: list<array{sha: string, parents: list<string>}>}
     *
     * @throws GitHubAppApiFailed
     * @throws \UnexpectedValueException
     */
    private function compare(int $installationId, string $path, string $from, string $to): array
    {
        $body = $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($path).'/compare/'.rawurlencode($from).'...'.rawurlencode($to));
        $status = $body['status'] ?? null;
        $total = $body['total_commits'] ?? null;
        $items = $body['commits'] ?? null;
        if (!\is_string($status) || !\is_int($total) || !\is_array($items) || !array_is_list($items)) {
            throw new \UnexpectedValueException('The compare answer has no status, total or commit list.');
        }

        $commits = [];
        foreach ($items as $item) {
            $sha = \is_array($item) ? ($item['sha'] ?? null) : null;
            $parents = \is_array($item) ? ($item['parents'] ?? null) : null;
            if (!\is_string($sha) || !\is_array($parents) || !array_is_list($parents)) {
                throw new \UnexpectedValueException('A compared commit has no sha or parent list.');
            }
            $parentShas = [];
            foreach ($parents as $parent) {
                $parentSha = \is_array($parent) ? ($parent['sha'] ?? null) : null;
                if (!\is_string($parentSha)) {
                    throw new \UnexpectedValueException('A parent of a compared commit has no sha.');
                }
                $parentShas[] = $parentSha;
            }
            $commits[] = ['sha' => $sha, 'parents' => $parentShas];
        }

        return ['status' => $status, 'complete' => \count($commits) >= $total, 'commits' => $commits];
    }

    private function unknown(ForgePullRequest $pullRequest, string $reason): ApprovalCoverage
    {
        $this->logger->warning('forge.approval_coverage_unreadable', [
            'pullRequestId' => (string) $pullRequest->id,
            'repository' => $pullRequest->repository,
            'reason' => $reason,
        ]);

        return ApprovalCoverage::Unknown;
    }
}
