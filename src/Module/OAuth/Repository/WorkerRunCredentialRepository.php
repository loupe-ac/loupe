<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use App\Module\OAuth\Entity\WorkerRunCredential;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkerRunCredential> */
class WorkerRunCredentialRepository extends ServiceEntityRepository
{
    /** How long a row outlives its expiry. A row keeps the mark that its session once held a credential. */
    public const string RETENTION = '30 days';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunCredential::class);
    }

    /**
     * Revokes every live token that was issued for one launch of a run. The
     * bridge id and the run key together name the launch, so a late close of
     * an older launch of the same session touches only its own tokens.
     */
    public function revokeForRun(Project $project, Uuid $bridgeId, Uuid $runKey): int
    {
        $parameters = [
            'projectId' => (string) $project->id,
            'bridgeId' => $bridgeId->toRfc4122(),
            'runKey' => $runKey->toRfc4122(),
        ];
        $issued = 'SELECT w.access_token_id FROM oauth_worker_run_credentials w
                   WHERE w.project_id = :projectId AND w.bridge_id = :bridgeId AND w.run_key = :runKey';

        return $this->getEntityManager()->getConnection()->transactional(static function (Connection $connection) use ($issued, $parameters): int {
            $connection->executeStatement(
                'UPDATE oauth2_refresh_token SET revoked = true WHERE revoked = false AND access_token IN ('.$issued.')',
                $parameters,
            );
            $revoked = (int) $connection->executeStatement(
                'UPDATE oauth2_access_token SET revoked = true WHERE revoked = false AND identifier IN ('.$issued.')',
                $parameters,
            );
            $connection->executeStatement(
                'UPDATE oauth_worker_run_credentials SET revoked_at = NOW()
                 WHERE revoked_at IS NULL AND project_id = :projectId AND bridge_id = :bridgeId AND run_key = :runKey',
                $parameters,
            );

            return $revoked;
        });
    }

    public function deleteExpiredBeyondRetention(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM oauth_worker_run_credentials WHERE expires_at < NOW() - CAST(:retention AS interval)',
            ['retention' => self::RETENTION],
        );
    }

    public function deleteForUser(Uuid $userId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM oauth_worker_run_credentials WHERE user_id = :userId',
            ['userId' => $userId->toRfc4122()],
        );
    }

    public function deleteForProject(Uuid $projectId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM oauth_worker_run_credentials WHERE project_id = :projectId',
            ['projectId' => $projectId->toRfc4122()],
        );
    }
}
