<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /** The screenshot of one comment, or null for a comment with none. */
    public function findOneByComment(SiteReviewComment $comment): ?SiteReviewScreenshot
    {
        return $this->findOneBy(['comment' => $comment]);
    }

    /**
     * The pixel size of the screenshots of these comments, keyed by comment
     * id. It selects no bytes, so a list costs no image data. A comment with
     * no screenshot is absent.
     *
     * @param list<SiteReviewComment> $comments
     *
     * @return array<string, array{width: int, height: int}>
     */
    public function findSizesByComments(array $comments): array
    {
        if ([] === $comments) {
            return [];
        }

        /** @var list<array{commentId: Uuid, width: int, height: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.comment) AS commentId', 's.width', 's.height')
            ->andWhere('s.comment IN (:comments)')
            ->setParameter('comments', $comments)
            ->getQuery()
            ->getArrayResult();

        $sizes = [];
        foreach ($rows as $row) {
            $sizes[(string) $row['commentId']] = ['width' => $row['width'], 'height' => $row['height']];
        }

        return $sizes;
    }
}
