<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Issues and revokes the access token of one worker launch. Bridge asks and
 * OAuth answers, because the two modules may not import each other.
 */
interface WorkerRunCredentialIssuerInterface
{
    /** A token that reaches one project as a worker. It has no refresh token and a short life. */
    public function issue(User $owner, Project $project, Uuid $bridgeId, Uuid $runKey, Uuid $sessionId): IssuedWorkerRunCredential;

    /** Revokes every live token that was issued for the launch, and returns how many it revoked. */
    public function revokeForRun(Project $project, Uuid $bridgeId, Uuid $runKey): int;
}
