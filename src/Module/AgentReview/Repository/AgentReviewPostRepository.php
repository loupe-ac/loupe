<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Repository;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AgentReviewPost> */
class AgentReviewPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentReviewPost::class);
    }

    /**
     * @param list<AgentReview> $reviews
     *
     * @return list<AgentReviewPost> the posts of those reviews, oldest first
     */
    public function findOfReviews(array $reviews): array
    {
        if ([] === $reviews) {
            return [];
        }

        /** @var list<AgentReviewPost> $posts */
        $posts = $this->createQueryBuilder('p')
            ->where('p.review IN (:reviews)')
            ->setParameter('reviews', $reviews)
            ->orderBy('p.createdAt')
            ->addOrderBy('p.id')
            ->getQuery()
            ->getResult();

        return $posts;
    }
}
