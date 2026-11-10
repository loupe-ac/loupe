<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\GitHubRepositoryPick;
use App\Module\GitHub\Service\DeliveryAnnouncer;
use App\Module\GitHub\Service\GitHubInstallationRecords;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use Psr\Log\LoggerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Adds the ticked repositories to the project. GitHub has no call that checks
 * one repository against one installation, so the handler lists each ticked
 * installation again with the owner's token. The posted path and installation
 * only name the pick. Whatever the list shows decides the claim.
 */
final readonly class AddGitHubRepositoriesHandler
{
    public function __construct(
        private GitHubInstallSession $installSession,
        private GitHubUserApi $userApi,
        private GitHubInstallationRecords $installationRecords,
        private DeliveryAnnouncer $announcer,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AddGitHubRepositoriesCommand $command): AddedGitHubRepositories
    {
        $projectId = (string) $command->project->id;
        $token = $this->installSession->token($projectId) ?? throw new DomainErrors(['token' => 'github.picker.flash.expired']);

        $byInstallation = [];
        foreach ($command->picks as $pick) {
            $byInstallation[$pick->installationId][$pick->repositoryId] = $pick;
        }

        $added = [];
        $refused = [];
        try {
            $listed = $this->userApi->installations($token);
            $plans = [];
            foreach ($byInstallation as $installationId => $picks) {
                $installation = array_find($listed, static fn ($candidate): bool => $candidate->id === $installationId);
                if (null === $installation) {
                    array_push($refused, ...array_map(static fn (GitHubRepositoryPick $pick): string => $pick->path, array_values($picks)));

                    continue;
                }

                $reached = [];
                foreach ($this->userApi->installationRepositories($token, $installationId)->repositories as $repository) {
                    $reached[$repository->id] = $repository;
                }

                $plans[] = [$installation, $installationId, $picks, $reached];
            }
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_api_failed', [
                'projectId' => $projectId,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);

            throw new DomainErrors(['github' => 'github.app.flash.github_unavailable']);
        }

        foreach ($plans as [$installation, $installationId, $picks, $reached]) {
            $recorded = false;
            foreach ($picks as $pick) {
                $repository = $reached[$pick->repositoryId] ?? null;
                if (null === $repository) {
                    $refused[] = $pick->path;

                    continue;
                }

                if (!$recorded) {
                    $this->installationRecords->sync($installation);
                    $recorded = true;
                }

                if (null === $this->announcer->claim($command->project, $repository, ForgeRepositorySource::Installation, $installationId)) {
                    $refused[] = $repository->fullName;

                    continue;
                }

                $added[] = $repository->fullName;
            }
        }

        $this->installSession->clear();
        $this->auditor->record(
            'github.repositories_added',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'added' => \count($added), 'refused' => \count($refused)],
            new AuditSubject('project', $projectId),
        );

        return new AddedGitHubRepositories($added, $refused);
    }
}
