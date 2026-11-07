<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Forge\Service\RepositorySettingProposer;
use App\Module\GitHub\GitHubDelivery;

/** Proposes a `.loupe.yaml` setting as a pull request, as the App installation that delivers the repository. */
final readonly class GitHubRepositorySettingProposer implements RepositorySettingProposer
{
    public function __construct(
        private GitHubAppApi $api,
        private GitHubRepositoryInstallations $installations,
        private GitHubWorkflowFileReader $reader,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function propose(ForgeRepository $repository, string $key, string $value, string $title, string $body): string
    {
        try {
            [$installationId, $path] = $this->installations->for($repository);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestWriteFailed($e->reason, permanent: true, previous: $e);
        }
        $projectId = $repository->project->id?->toRfc4122() ?? throw new \LogicException('The project has no id.');
        $repositoryPath = GitHubPullRequestInstallations::repositoryPath($path);
        $owner = explode('/', $path, 2)[0];

        try {
            $file = $this->reader->read($repository);
            $branch = self::branch($key, $value);
            $existing = $this->openPullRequest($installationId, $repositoryPath, $owner, $branch, $file->defaultBranch);
            if (null !== $existing) {
                return $existing;
            }
            if ($file->exists && null === $file->content) {
                throw new PullRequestWriteFailed('file_unusable', permanent: true);
            }

            $content = LoupeYamlSettingEditor::edit($file->content, $key, $value, $projectId);
            if ($content === $file->content) {
                throw new PullRequestWriteFailed('already_set', permanent: true);
            }

            [$blobSha, $branchContent] = [$file->blobSha, null];
            if (!$this->createBranch($installationId, $repositoryPath, $branch, $file->headSha)) {
                [$blobSha, $branchContent] = $this->branchFile($installationId, $repositoryPath, $branch);
            }
            if ($branchContent !== $content) {
                $this->api->put($installationId, $repositoryPath.'/contents/'.GitHubWorkflowFileReader::FILE, [
                    'message' => $title,
                    'content' => base64_encode($content),
                    'branch' => $branch,
                ] + (null === $blobSha ? [] : ['sha' => $blobSha]));
            }

            return self::url($this->api->post($installationId, $repositoryPath.'/pulls', [
                'title' => $title,
                'head' => $branch,
                'base' => $file->defaultBranch,
                'body' => $body,
                'draft' => false,
            ]));
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestWriteFailed($e->reason, permanent: true, previous: $e);
        } catch (GitHubAppApiFailed $e) {
            throw GitHubWriteFailures::from($e);
        }
    }

    /** @throws GitHubAppApiFailed */
    private function openPullRequest(int $installationId, string $repositoryPath, string $owner, string $branch, string $base): ?string
    {
        $open = $this->api->get($installationId, $repositoryPath.'/pulls', ['head' => $owner.':'.$branch, 'base' => $base, 'state' => 'open']);

        return [] === $open ? null : self::url($open[0] ?? null);
    }

    /**
     * A branch that exists already is kept as it is. A force update could drop a commit that a person pushed there.
     *
     * @return bool false when the branch exists already
     *
     * @throws GitHubAppApiFailed
     */
    private function createBranch(int $installationId, string $repositoryPath, string $branch, string $headSha): bool
    {
        try {
            $this->api->post($installationId, $repositoryPath.'/git/refs', ['ref' => 'refs/heads/'.$branch, 'sha' => $headSha]);
        } catch (GitHubAppApiFailed $e) {
            if ('http_status' === $e->reason && 422 === $e->status && !$e->rateLimited) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Reads the file on a branch that an earlier proposal left. A write there must name the blob of that branch,
     * because the forge refuses a blob of the default branch when the two differ.
     *
     * @return array{?string, ?string} the blob sha and the content, or two nulls when the branch has no such file
     *
     * @throws GitHubAppApiFailed
     */
    private function branchFile(int $installationId, string $repositoryPath, string $branch): array
    {
        try {
            $file = $this->api->get($installationId, $repositoryPath.'/contents/'.GitHubWorkflowFileReader::FILE, ['ref' => $branch]);
        } catch (GitHubAppApiFailed $e) {
            if ('http_status' === $e->reason && 404 === $e->status && !$e->rateLimited) {
                return [null, null];
            }

            throw $e;
        }

        $blob = $file['sha'] ?? null;
        if (!\is_string($blob) || '' === $blob) {
            throw new GitHubAppApiFailed('malformed_body');
        }
        $encoded = $file['content'] ?? null;
        $content = \is_string($encoded) && 'base64' === ($file['encoding'] ?? null) ? base64_decode(str_replace("\n", '', $encoded), true) : false;

        return [$blob, false === $content ? null : $content];
    }

    private static function branch(string $key, string $value): string
    {
        return 'loupe/'.trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($key.'-'.$value)) ?? '', '-');
    }

    /** @throws PullRequestWriteFailed */
    private static function url(mixed $pullRequest): string
    {
        $url = \is_array($pullRequest) ? ($pullRequest['html_url'] ?? null) : null;

        return \is_string($url) && '' !== $url ? $url : throw new PullRequestWriteFailed('malformed_body', permanent: true);
    }
}
