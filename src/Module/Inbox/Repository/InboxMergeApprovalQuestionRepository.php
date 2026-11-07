<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxMergeApprovalQuestion;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InboxMergeApprovalQuestion> */
class InboxMergeApprovalQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxMergeApprovalQuestion::class);
    }

    /** @return list<InboxMergeApprovalQuestion> */
    public function findOpenForProject(Project $project): array
    {
        return $this->createQueryBuilder('question')
            ->addSelect('item')
            ->join('question.item', 'item')
            ->andWhere('question.project = :project')
            ->andWhere('question.endedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();
    }

    public function findOneForItem(InboxItem $item): ?InboxMergeApprovalQuestion
    {
        return $this->findOneBy(['item' => $item]);
    }
}
