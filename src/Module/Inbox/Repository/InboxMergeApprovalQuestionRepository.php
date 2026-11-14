<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxMergeApprovalQuestion;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /**
     * @param list<Uuid> $projectIds
     *
     * @return array<string, string> the pull request address by repository id, for each open row whose question has an answer and a pull request
     */
    public function findAnsweredPullRequestUrls(array $projectIds): array
    {
        if ([] === $projectIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('question')
            ->select('question.repositoryId AS repositoryId', 'question.pullRequestUrl AS url')
            ->andWhere('IDENTITY(question.project) IN (:projects)')
            ->andWhere('question.endedAt IS NULL')
            ->andWhere('question.answer IS NOT NULL')
            ->andWhere('question.pullRequestUrl IS NOT NULL')
            ->setParameter('projects', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $projectIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $urls = [];
        foreach ($rows as $row) {
            $urls[(string) $row['repositoryId']] = (string) $row['url'];
        }

        return $urls;
    }
}
