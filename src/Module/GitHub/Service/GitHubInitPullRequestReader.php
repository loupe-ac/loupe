<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\InitPullRequestReader;
use App\Module\Forge\Service\MergeApprovals;
use App\Module\GitHub\GitHubDelivery;
use App\Module\Workflow\Contract\WorkflowSettings;
use Psr\Log\LoggerInterface;

/** Reads `.loupe.yaml` at the head of the open pull request from the branch of `loupe init`, as the App installation that delivers the repository. */
final readonly class GitHubInitPullRequestReader implements InitPullRequestReader
{
    public function __construct(
        private GitHubAppApi $api,
        private GitHubRepositoryInstallations $installations,
        private WorkflowSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function setsMergeSetting(ForgeRepository $repository): bool
    {
        try {
            [$installationId, $path] = $this->installations->for($repository);
            $repositoryPath = GitHubPullRequestInstallations::repositoryPath($path);
            $owner = explode('/', $path, 2)[0];

            $open = $this->api->get($installationId, $repositoryPath.'/pulls', ['head' => $owner.':'.self::BRANCH, 'state' => 'open']);
            $sha = $open[0]['head']['sha'] ?? null;
            if (!\is_string($sha) || 1 !== preg_match('/^[0-9a-f]{40,64}$/', $sha)) {
                return false;
            }

            $content = $this->fileAt($installationId, $repositoryPath, $sha);
        } catch (GitHubInstallationUnavailable|GitHubAppApiFailed $e) {
            $this->logger->info('github.init_pull_request_unreadable', ['repositoryId' => (string) $repository->id, 'reason' => $e->reason]);

            return false;
        }
        if (null === $content) {
            return false;
        }

        $projectId = $repository->project->requireId();
        $workflow = WorkflowFileSettings::forProject($content, $projectId);
        if (null === $workflow || !\array_key_exists(MergeApprovals::SETTING, $workflow)) {
            return false;
        }

        return $this->settings->accepts($projectId, MergeApprovals::SETTING, $workflow[MergeApprovals::SETTING]);
    }

    /**
     * @return string|null the file text, or null when the commit holds no usable file
     *
     * @throws GitHubAppApiFailed
     */
    private function fileAt(int $installationId, string $repositoryPath, string $sha): ?string
    {
        try {
            $file = $this->api->get($installationId, $repositoryPath.'/contents/'.GitHubWorkflowFileReader::FILE, ['ref' => $sha]);
        } catch (GitHubAppApiFailed $e) {
            if ('http_status' === $e->reason && 404 === $e->status && !$e->rateLimited) {
                return null;
            }

            throw $e;
        }

        $size = $file['size'] ?? null;
        $encoded = $file['content'] ?? null;
        if ('file' !== ($file['type'] ?? null) || !\is_int($size) || $size > GitHubWorkflowFileReader::MAX_BYTES || 'base64' !== ($file['encoding'] ?? null) || !\is_string($encoded)) {
            return null;
        }

        $content = base64_decode(str_replace("\n", '', $encoded), true);

        return false === $content || \strlen($content) > GitHubWorkflowFileReader::MAX_BYTES ? null : $content;
    }
}
