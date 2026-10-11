<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestChangeFingerprint;
use App\Module\Forge\Service\PullRequestChangeFingerprintReader;
use App\Module\GitHub\GitHubDelivery;
use Psr\Log\LoggerInterface;

/** Hashes the file list of one compare of the base branch with the head, as the pull request shows it. */
final readonly class GitHubPullRequestChangeFingerprintReader implements PullRequestChangeFingerprintReader
{
    /** GitHub's compare lists 300 files at most, so a list that long may be cut. */
    private const int FILE_LIMIT = 300;

    public function __construct(
        private GitHubAppApi $api,
        private GitHubPullRequestInstallations $installations,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function read(ForgePullRequest $pullRequest, string $headSha): ?PullRequestChangeFingerprint
    {
        $base = $pullRequest->baseBranch;
        if (null === $base || '' === $base || '' === $headSha) {
            return null;
        }

        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
            $body = $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($path).'/compare/'.rawurlencode($base).'...'.rawurlencode($headSha));

            return $this->fingerprint($body);
        } catch (GitHubInstallationUnavailable|GitHubAppApiFailed $e) {
            return $this->unreadable($pullRequest, $e->reason);
        } catch (\UnexpectedValueException) {
            return $this->unreadable($pullRequest, 'malformed_body');
        }
    }

    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    private function fingerprint(array $body): ?PullRequestChangeFingerprint
    {
        $files = $body['files'] ?? null;
        if (!\is_array($files) || !array_is_list($files)) {
            throw new \UnexpectedValueException('The compare answer has no file list.');
        }
        if (\count($files) >= self::FILE_LIMIT) {
            return null;
        }

        $change = [];
        $lines = [];
        foreach ($files as $file) {
            $filename = \is_array($file) ? ($file['filename'] ?? null) : null;
            $status = \is_array($file) ? ($file['status'] ?? null) : null;
            if (!\is_array($file) || !\is_string($filename) || !\is_string($status)) {
                throw new \UnexpectedValueException('A compared file has no filename or status.');
            }
            $previous = $file['previous_filename'] ?? null;
            $header = $status.' '.(\is_string($previous) ? $previous : '').' '.$filename;
            $patch = $file['patch'] ?? null;
            if (\is_string($patch)) {
                $change[$filename] = $header."\n".preg_replace('/^@@ -\d+(?:,\d+)? \+\d+(?:,\d+)? @@/m', '@@ @@', $patch);
                $lines[$filename] = $header."\n".$patch;

                continue;
            }
            $changes = $file['changes'] ?? null;
            if (!\is_int($changes)) {
                throw new \UnexpectedValueException('A compared file has no patch and no change count.');
            }
            // The head blob of a binary or very large file does not show a change on the base side, so only a pure rename or mode change keeps a fingerprint.
            if (0 !== $changes) {
                return null;
            }
            $change[$filename] = $lines[$filename] = $header;
        }
        ksort($change, \SORT_STRING);
        ksort($lines, \SORT_STRING);

        return new PullRequestChangeFingerprint(
            hash('sha256', implode("\n", $change)),
            hash('sha256', implode("\n", $lines)),
        );
    }

    private function unreadable(ForgePullRequest $pullRequest, string $reason): null
    {
        $this->logger->warning('forge.change_fingerprint_unreadable', [
            'pullRequestId' => (string) $pullRequest->id,
            'repository' => $pullRequest->repository,
            'reason' => $reason,
        ]);

        return null;
    }
}
