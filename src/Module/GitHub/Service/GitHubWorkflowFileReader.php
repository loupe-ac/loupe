<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;

/**
 * Reads `.loupe.yaml` as the App installation that delivers the repository.
 * It names the head commit of the default branch first, and then reads the
 * file at that commit, so both answers describe one tree.
 */
final readonly class GitHubWorkflowFileReader
{
    public const string FILE = '.loupe.yaml';
    public const int MAX_BYTES = 65536;

    public function __construct(
        private GitHubRepositoryInstallations $installations,
        private GitHubAppApi $api,
    ) {
    }

    /**
     * @throws GitHubInstallationUnavailable
     * @throws GitHubAppApiFailed
     */
    public function read(ForgeRepository $repository): GitHubWorkflowFile
    {
        [$installationId, $path] = $this->installations->for($repository);
        $repositoryPath = GitHubPullRequestInstallations::repositoryPath($path);

        $default = $this->api->get($installationId, $repositoryPath)['default_branch'] ?? null;
        if (!\is_string($default) || '' === $default) {
            throw new GitHubAppApiFailed('malformed_body');
        }

        $head = $this->api->get($installationId, $repositoryPath.'/branches/'.implode('/', array_map(rawurlencode(...), explode('/', $default))))['commit']['sha'] ?? null;
        if (!\is_string($head) || 1 !== preg_match('/^[0-9a-f]{40,64}$/', $head)) {
            throw new GitHubAppApiFailed('malformed_body');
        }

        try {
            $file = $this->api->get($installationId, $repositoryPath.'/contents/'.self::FILE, ['ref' => $head]);
        } catch (GitHubAppApiFailed $e) {
            if ('http_status' === $e->reason && 404 === $e->status && !$e->rateLimited) {
                return GitHubWorkflowFile::missing($head, $default);
            }

            throw $e;
        }

        $blob = $file['sha'] ?? null;
        $size = $file['size'] ?? null;
        $encoded = $file['content'] ?? null;
        if ('file' !== ($file['type'] ?? null) || !\is_string($blob) || '' === $blob || !\is_int($size) || $size > self::MAX_BYTES || 'base64' !== ($file['encoding'] ?? null) || !\is_string($encoded)) {
            return GitHubWorkflowFile::unusable($head, $default);
        }

        $content = base64_decode(str_replace("\n", '', $encoded), true);
        if (false === $content || \strlen($content) > self::MAX_BYTES) {
            return GitHubWorkflowFile::unusable($head, $default);
        }

        return new GitHubWorkflowFile($head, $blob, $content, $default);
    }
}
