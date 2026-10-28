<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\Forge\Command\RunRepositoryCheckCommand;
use App\Module\Forge\Command\RunRepositoryCheckHandler;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Reads GitHub again for one repository, in the request. The limiter counts
 * each click for the repository, so a refused click reads nothing.
 */
final readonly class CheckGitHubRepositoryHandler
{
    public function __construct(
        private RunRepositoryCheckHandler $runCheck,
        private ForgeRepositoryCheckRepository $forgeRepositoryChecks,

        #[Autowire(service: 'limiter.forge_repository_check')]
        private RateLimiterFactoryInterface $limiter,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CheckGitHubRepositoryCommand $command): CheckGitHubRepositoryResult
    {
        $project = $command->project;
        $repository = $command->repository;
        if (null === $project->id || true !== $repository->project->id?->equals($project->id)) {
            throw new DomainErrors(['repository' => 'github.repositories.flash.repository_missing']);
        }

        $context = ['projectId' => (string) $project->id, 'repositoryId' => (string) $repository->id];
        $subject = new AuditSubject('project', (string) $project->id);

        if (!$this->limiter->create((string) $repository->id)->consume()->isAccepted()) {
            $check = $this->forgeRepositoryChecks->findOneOfRepository($repository);
            $this->auditor->record('github.repository_check_requested', AuditOutcome::Refused, $context, $subject);

            return new CheckGitHubRepositoryResult(true, null === $check ? null : max($check->checkedAt, $check->attemptedAt));
        }

        ($this->runCheck)(new RunRepositoryCheckCommand($repository, CheckTrigger::CheckAgain));
        $this->auditor->record('github.repository_check_requested', AuditOutcome::Success, $context, $subject);

        return new CheckGitHubRepositoryResult(false);
    }
}
