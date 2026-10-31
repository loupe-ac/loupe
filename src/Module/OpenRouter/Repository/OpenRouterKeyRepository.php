<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Repository;

use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Module\OpenRouter\View\OpenRouterKeySummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
