<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Repository;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /** @return array<string, array{AgentReviewConclusion, int}> the conclusion and the version number of the newest review of each document for the card, keyed by document id */
    public function findNewestConclusionByDocumentForCard(Uuid $cardId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (document_id) document_id, conclusion, version_number FROM document_agent_reviews WHERE card_id = :card ORDER BY document_id, created_at DESC, id DESC',
            ['card' => $cardId->toRfc4122()],
        );

        $conclusions = [];
        foreach ($rows as $row) {
            $conclusion = AgentReviewConclusion::tryFrom((string) $row['conclusion']);
            if (null !== $conclusion) {
                $conclusions[(string) $row['document_id']] = [$conclusion, (int) $row['version_number']];
            }
        }

        return $conclusions;
    }

    /**
     * @return array<string, array{failed: int, ownerPasses: int}> the failed reviews, and the passed reviews with a finding for the owner, of each document for the card, keyed by document id
     */
    public function countRoundsByDocumentForCard(Card $card): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT document_id,
                       COUNT(*) FILTER (WHERE conclusion = 'failure') AS failed,
                       COUNT(*) FILTER (WHERE conclusion = 'success' AND EXISTS (
                           SELECT 1 FROM json_array_elements(findings) f WHERE (f->>'needsPerson')::boolean
                       )) AS owner_passes
                FROM document_agent_reviews
                WHERE card_id = :card
                GROUP BY document_id
                SQL,
            ['card' => ($card->id ?? throw new \LogicException('A stored card has an id.'))->toRfc4122()],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['document_id']] = ['failed' => (int) $row['failed'], 'ownerPasses' => (int) $row['owner_passes']];
        }

        return $counts;
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
