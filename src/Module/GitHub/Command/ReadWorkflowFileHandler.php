<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Service\GitHubAppApiFailed;
use App\Module\GitHub\Service\GitHubInstallationUnavailable;
use App\Module\GitHub\Service\GitHubWorkflowFileReader;
use App\Module\GitHub\Service\WorkflowFileSettings;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads `.loupe.yaml` at the head of the default branch and stores what its
 * `workflow:` map says. The row lock serializes the reads of one repository,
 * so the last write always matches the newest head that any read saw. A read
 * that fails leaves every stored row as it is.
 */
final readonly class ReadWorkflowFileHandler
{
    private const int MAX_WARNINGS = 20;
    private const int MAX_WARNING_WIDTH = 300;

    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubWorkflowFileReader $reader,
        private RepositoryWorkflowSettingsWriter $writer,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReadWorkflowFileCommand $command): void
    {
        $this->em->wrapInTransaction(function () use ($command): void {
            $repository = $this->forgeRepositories->findOneLocked($command->repositoryId);
            if (null === $repository || GitHubDelivery::FORGE !== $repository->forge) {
                return;
            }

            try {
                $file = $this->reader->read($repository);
            } catch (GitHubInstallationUnavailable|GitHubAppApiFailed $e) {
                $this->logger->info('github.workflow_file_unreadable', ['repositoryId' => (string) $command->repositoryId, 'reason' => $e->reason]);

                return;
            }

            $projectId = $repository->project->id ?? throw new \LogicException('A persisted project has an id.');
            $settings = null === $file->content ? null : WorkflowFileSettings::forProject($file->content, $projectId);
            $blob = null === $settings ? null : $file->blobSha;
            if (null !== $blob && $this->writer->isBlobUnchanged($command->repositoryId, $blob)) {
                $this->logger->info('github.workflow_file_unchanged', ['repositoryId' => (string) $command->repositoryId, 'blobSha' => $blob]);

                return;
            }

            $result = $this->writer->apply($projectId, $command->repositoryId, $blob, $file->headSha, $settings ?? [], null !== $settings);
            $repository->fileWarnings = array_map(
                static fn (string $warning): string => mb_strimwidth($warning, 0, self::MAX_WARNING_WIDTH, '...'),
                \array_slice($result->warnings, 0, self::MAX_WARNINGS),
            );
            $this->em->flush();

            $this->logger->info('github.workflow_file_read', [
                'repositoryId' => (string) $command->repositoryId,
                'usable' => null !== $settings,
                'warnings' => \count($result->warnings),
            ]);
        });
    }
}
