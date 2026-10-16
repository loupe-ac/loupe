<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Repository;

use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentAgentReview> */
class DocumentAgentReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentAgentReview::class);
    }

    public function findLatestOfDocumentForCard(Document $document, Card $card): ?DocumentAgentReview
    {
        return $this->findOneBy(['document' => $document, 'card' => $card], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<DocumentAgentReview> the reviews of the document, newest first */
    public function findOfDocument(Document $document): array
    {
        return $this->findBy(['document' => $document], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return int the number of reviews deleted */
    public function deleteByProject(Project $project): int
    {
        $deleted = $this->getEntityManager()
            ->createQuery('DELETE App\Module\AgentReview\Entity\DocumentAgentReview r WHERE r.project = :project')
            ->setParameter('project', $project)
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
