<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Messenger\ReadRepositoryWorkflowSettings;
use App\Module\GitHub\Service\GitHubAppApiFailed;
use App\Module\GitHub\Service\GitHubInstallationUnavailable;
use App\Module\GitHub\Service\GitHubWorkflowFileReader;
use App\Module\GitHub\Service\WorkflowFileSettings;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsApplied;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsUnread;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Reads `.loupe.yaml` at the head of the default branch and stores what its
 * `workflow:` map says. The row lock serializes the reads of one repository,
 * so the last write always matches the newest head that any read saw. A read
 * that fails leaves every stored row as it is. A forced read reconciles also
 * when the blob is unchanged, and tries again when GitHub fails.
 */
final readonly class ReadWorkflowFileHandler
{
    private const string UNREADABLE = 'unreadable';
    private const string UNCHANGED = 'unchanged';
    private const int MAX_WARNINGS = 20;
    private const int MAX_WARNING_WIDTH = 300;
    private const int MAX_FORCED_ATTEMPTS = 3;
    private const int FORCED_RETRY_DELAY_SECONDS = 60;

    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubWorkflowFileReader $reader,
        private RepositoryWorkflowSettingsWriter $writer,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(ReadWorkflowFileCommand $command): void
    {
        $projectId = null;
        $outcome = null;
        $this->em->wrapInTransaction(function () use ($command, &$projectId, &$outcome): void {
            $repository = $this->forgeRepositories->findOneLocked($command->repositoryId);
            if (null === $repository || GitHubDelivery::FORGE !== $repository->forge) {
                return;
            }
            $projectId = $repository->project->id ?? throw new \LogicException('A persisted project has an id.');

            try {
                $file = $this->reader->read($repository);
            } catch (GitHubInstallationUnavailable|GitHubAppApiFailed $e) {
                $this->logger->info('github.workflow_file_unreadable', ['repositoryId' => (string) $command->repositoryId, 'reason' => $e->reason]);
                $outcome = self::UNREADABLE;

                return;
            }

            $settings = null === $file->content ? null : WorkflowFileSettings::forProject($file->content, $projectId);
            $blob = null === $settings ? null : $file->blobSha;
            if (null !== $blob && $this->writer->isBlobUnchanged($command->repositoryId, $blob)) {
                $this->logger->info('github.workflow_file_unchanged', ['repositoryId' => (string) $command->repositoryId, 'blobSha' => $blob]);
                $outcome = self::UNCHANGED;

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

        if (!$command->force || null === $projectId) {
            return;
        }
        if (self::UNCHANGED === $outcome) {
            $this->dispatcher->dispatch(new RepositoryWorkflowSettingsApplied($projectId, $command->repositoryId));
        } elseif (self::UNREADABLE === $outcome) {
            $this->retryOrGiveUp($command, $projectId);
        }
    }

    private function retryOrGiveUp(ReadWorkflowFileCommand $command, Uuid $projectId): void
    {
        if ($command->attempt < self::MAX_FORCED_ATTEMPTS) {
            $this->bus->dispatch(
                new ReadRepositoryWorkflowSettings((string) $command->repositoryId, true, $command->attempt + 1),
                [new DelayStamp(self::FORCED_RETRY_DELAY_SECONDS * 1000)],
            );

            return;
        }

        $this->logger->info('github.init_read_gave_up', ['repositoryId' => (string) $command->repositoryId, 'attempt' => $command->attempt]);
        $this->dispatcher->dispatch(new RepositoryWorkflowSettingsUnread($projectId, $command->repositoryId));
    }
}
