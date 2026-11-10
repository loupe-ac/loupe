<?php

declare(strict_types=1);

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\WaitlistBlockPattern;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WaitlistBlockPattern>
 */
class WaitlistBlockPatternRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WaitlistBlockPattern::class);
    }

    /** @return list<WaitlistBlockPattern> */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['pattern' => 'ASC']);
    }

    public function findOneByPattern(string $pattern): ?WaitlistBlockPattern
    {
        return $this->findOneBy(['pattern' => strtolower(trim($pattern))]);
    }

    /**
     * Stores the pattern and returns its id, or null when the pattern exists.
     *
     * The insert yields to a concurrent winner, so a lost race never raises and
     * never closes the EntityManager.
     */
    public function insertIfAbsent(string $pattern): ?Uuid
    {
        $pattern = strtolower(trim($pattern));
        $id = Uuid::v7();

        $inserted = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO waitlist_block_patterns (id, pattern, like_pattern, created_at) VALUES (:id, :pattern, :likePattern, :createdAt) ON CONFLICT (pattern) DO NOTHING',
            [
                'id' => $id->toRfc4122(),
                'pattern' => $pattern,
                'likePattern' => WaitlistBlockPattern::computeLikePattern($pattern),
                'createdAt' => new \DateTimeImmutable(),
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return 1 === $inserted ? $id : null;
    }

    /** Uses the same LIKE as the hide filter of WaitlistEntryRepository. */
    public function matches(string $email): bool
    {
        return (bool) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere(":email LIKE p.likePattern ESCAPE '\\'")
            ->setParameter('email', strtolower($email))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
