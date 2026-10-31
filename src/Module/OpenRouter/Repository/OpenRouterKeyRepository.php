<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Repository;

use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Module\OpenRouter\View\OpenRouterKeySummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every read and delete that a page or a purge needs goes by id and selects no
 * secret, so an unreadable encryption key cannot break them.
 *
 * @extends ServiceEntityRepository<OpenRouterKey>
 */
final class OpenRouterKeyRepository extends ServiceEntityRepository
{
    private const string SUMMARY = 'SELECT NEW App\Module\OpenRouter\View\OpenRouterKeySummary(k.id, %s, k.lastFour, k.state, k.refusedSince, k.lastReadAt, k.createdAt) FROM App\Module\OpenRouter\Entity\OpenRouterKey k WHERE k.%s = :owner';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpenRouterKey::class);
    }

    public function findSummaryForUser(Uuid $userId): ?OpenRouterKeySummary
    {
        return $this->getEntityManager()
            ->createQuery(\sprintf(self::SUMMARY, 'false', 'user'))
            ->setParameter('owner', $userId, UuidType::NAME)
            ->getOneOrNullResult();
    }

    public function findSummaryForProject(Uuid $projectId): ?OpenRouterKeySummary
    {
        return $this->getEntityManager()
            ->createQuery(\sprintf(self::SUMMARY, 'true', 'project'))
            ->setParameter('owner', $projectId, UuidType::NAME)
            ->getOneOrNullResult();
    }

    /**
     * The account key of a user first, then the keys of the projects they own.
     *
     * @return list<array{projectId: ?string, state: OpenRouterKeyState, createdAt: \DateTimeImmutable, refusedSince: ?\DateTimeImmutable, lastReadAt: ?\DateTimeImmutable}>
     */
    public function findExportRowsForUser(Uuid $userId): array
    {
        $account = $this->getEntityManager()
            ->createQuery('SELECT k.state AS state, k.createdAt AS createdAt, k.refusedSince AS refusedSince, k.lastReadAt AS lastReadAt FROM '.OpenRouterKey::class.' k WHERE k.user = :owner')
            ->setParameter('owner', $userId, UuidType::NAME)
            ->getResult();
        $projects = $this->getEntityManager()
            ->createQuery('SELECT p.id AS projectId, k.state AS state, k.createdAt AS createdAt, k.refusedSince AS refusedSince, k.lastReadAt AS lastReadAt FROM '.OpenRouterKey::class.' k JOIN k.project p WHERE p.owner = :owner ORDER BY k.createdAt')
            ->setParameter('owner', $userId, UuidType::NAME)
            ->getResult();

        $rows = [];
        foreach ($account as $row) {
            $rows[] = ['projectId' => null, 'state' => $row['state'], 'createdAt' => $row['createdAt'], 'refusedSince' => $row['refusedSince'], 'lastReadAt' => $row['lastReadAt']];
        }
        foreach ($projects as $row) {
            $rows[] = ['projectId' => (string) $row['projectId'], 'state' => $row['state'], 'createdAt' => $row['createdAt'], 'refusedSince' => $row['refusedSince'], 'lastReadAt' => $row['lastReadAt']];
        }

        return $rows;
    }

    /** @return ?Uuid the id of the row the delete removed, so an event names that row and no other */
    public function deleteReturningIdForUser(Uuid $userId): ?Uuid
    {
        return $this->deleteReturningId('user_id', $userId);
    }

    /** @return ?Uuid the id of the row the delete removed, so an event names that row and no other */
    public function deleteReturningIdForProject(Uuid $projectId): ?Uuid
    {
        return $this->deleteReturningId('project_id', $projectId);
    }

    /**
     * Records one health reading of the key under a row lock, so concurrent reads
     * change the key in turn. Returns the state before the reading, or null when the key is gone.
     */
    public function recordHealth(Uuid $keyId, bool $refused, \DateTimeImmutable $now): ?OpenRouterKeyState
    {
        return $this->getEntityManager()->getConnection()->transactional(static function (Connection $connection) use ($keyId, $refused, $now): ?OpenRouterKeyState {
            $id = $keyId->toRfc4122();
            $before = $connection->fetchOne('SELECT state FROM openrouter_keys WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (false === $before) {
                return null;
            }

            $connection->executeStatement(
                $refused
                    ? "UPDATE openrouter_keys SET state = 'refused', refused_since = :now WHERE id = :id AND state = 'working'"
                    : "UPDATE openrouter_keys SET state = 'working', refused_since = NULL, last_read_at = :now WHERE id = :id",
                ['id' => $id, 'now' => $now],
                ['now' => Types::DATETIME_IMMUTABLE],
            );

            return OpenRouterKeyState::from((string) $before);
        });
    }

    /**
     * Locks the key row for share inside the caller's transaction, so a delete or
     * a health change waits for that transaction. Returns null when the key is gone.
     */
    public function lockState(Uuid $keyId): ?OpenRouterKeyState
    {
        $state = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT state FROM openrouter_keys WHERE id = :id FOR SHARE',
            ['id' => $keyId->toRfc4122()],
        );

        return false === $state ? null : OpenRouterKeyState::from((string) $state);
    }

    /** @return int the number of rows deleted */
    public function deleteForUser(Uuid $userId): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE '.OpenRouterKey::class.' k WHERE k.user = :owner')
            ->setParameter('owner', $userId, UuidType::NAME)
            ->execute();
    }

    /** @return int the number of rows deleted */
    public function deleteForProject(Uuid $projectId): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE '.OpenRouterKey::class.' k WHERE k.project = :owner')
            ->setParameter('owner', $projectId, UuidType::NAME)
            ->execute();
    }

    private function deleteReturningId(string $column, Uuid $ownerId): ?Uuid
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'DELETE FROM openrouter_keys WHERE '.$column.' = :owner RETURNING id',
            ['owner' => $ownerId->toRfc4122()],
        );

        return false === $id ? null : Uuid::fromString((string) $id);
    }
}
