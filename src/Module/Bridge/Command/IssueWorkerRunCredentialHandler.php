<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Project\Repository\ProjectRepository;
use App\Security\IssuedWorkerRunCredential;
use App\Security\WorkerRunCredentialIssuerInterface;

/**
 * Issues the credential of one worker launch. It reads no worker run row,
 * because the bridge reports its runs through a queue and the first report can
 * arrive after the launch asks for its credential.
 */
final readonly class IssueWorkerRunCredentialHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunCredentialIssuerInterface $issuer,
    ) {
    }

    /** Null when the owner has no project under the handle. */
    public function __invoke(IssueWorkerRunCredentialCommand $command): ?IssuedWorkerRunCredential
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        return $this->issuer->issue($command->owner, $project, $command->bridgeId, $command->runKey, $command->sessionId);
    }
}
