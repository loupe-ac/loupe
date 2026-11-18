<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Security\IssuedWorkerRunCredential;
use App\Security\WorkerRunCredentialIssuerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A run token for a test that acts as a bridge worker. It goes through the
 * issuer the mint route uses, so the token is the one a launch receives.
 */
final readonly class WorkerRunToken
{
    public static function issue(
        ContainerInterface $container,
        User $owner,
        Project $project,
        ?Uuid $bridgeId = null,
        ?Uuid $runKey = null,
        ?Uuid $sessionId = null,
    ): IssuedWorkerRunCredential {
        $issuer = $container->get(WorkerRunCredentialIssuerInterface::class);
        if (!$issuer instanceof WorkerRunCredentialIssuerInterface) {
            throw new \LogicException(WorkerRunCredentialIssuerInterface::class.' is missing.');
        }

        return $issuer->issue($owner, $project, $bridgeId ?? Uuid::v7(), $runKey ?? Uuid::v7(), $sessionId ?? Uuid::v7());
    }
}
