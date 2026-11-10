<?php

declare(strict_types=1);

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\WaitlistBlockPattern;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
