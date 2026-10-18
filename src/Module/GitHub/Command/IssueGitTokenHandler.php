<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Service\GitHubGitTokenIssuer;
use App\Module\GitHub\Service\GitHubGitTokenRefused;
use App\Module\GitHub\Service\GitHubScopedToken;
use App\Module\Project\Repository\ProjectRepository;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Issues a push token limited to the repository of one of the caller's
 * projects. A project that keeps its own git setup gets none.
 */
final readonly class IssueGitTokenHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private GitHubGitTokenIssuer $issuer,
        private Auditor $auditor,
    ) {
    }

    /**
     * @throws DomainErrors
     * @throws GitHubGitTokenRefused
     */
    public function __invoke(IssueGitTokenCommand $command): GitHubScopedToken
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            throw new DomainErrors(['handle' => 'github.git_token.error.project_not_found']);
        }

        $projectId = $project->requireId()->toRfc4122();
        if (!$project->gitIdentityManaged) {
            $this->auditor->record('github.git_token_refused', AuditOutcome::Refused, ['projectId' => $projectId, 'cause' => 'not_managed'], new AuditSubject('project', $projectId));

            throw new DomainErrors(['gitIdentity' => 'github.git_token.error.not_managed']);
        }

        try {
            $token = $this->issuer->issue($project);
        } catch (GitHubGitTokenRefused $refused) {
            $this->auditor->record('github.git_token_refused', AuditOutcome::Refused, ['projectId' => $projectId, 'cause' => $refused->cause], new AuditSubject('project', $projectId));

            throw $refused;
        }

        $this->auditor->record('github.git_token_issued', AuditOutcome::Success, ['projectId' => $projectId], new AuditSubject('project', $projectId));

        return $token;
    }
}
