<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteReviewScreenshot>
 */
class SiteReviewScreenshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReviewScreenshot::class);
    }

    /**
     * The screenshots of these comments, keyed by comment id. A comment with
     * none is absent.
     *
     * @param list<SiteReviewComment> $comments
     *
     * @return array<string, SiteReviewScreenshot>
     */
    public function findByComments(array $comments): array
    {
        if ([] === $comments) {
            return [];
        }

        /** @var list<SiteReviewScreenshot> $screenshots */
        $screenshots = $this->createQueryBuilder('s')
            ->andWhere('s.comment IN (:comments)')
            ->setParameter('comments', $comments)
            ->getQuery()
            ->getResult();

        $byComment = [];
        foreach ($screenshots as $screenshot) {
            $byComment[(string) $screenshot->comment->id] = $screenshot;
        }

        return $byComment;
    }
}
