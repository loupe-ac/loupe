<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<CardDocument> */
final class CardDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardDocument::class);
    }

    /** @return array<string, int> card id => linked documents; a card with none has no key */
    public function countsForProject(Project $project): array
    {
        /** @var list<array{cardId: string, total: int}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('card.id AS cardId', 'COUNT(link.id) AS total')
            ->join('link.card', 'card')
            ->where('card.project = :project')
            ->setParameter('project', $project)
            ->groupBy('card.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['cardId']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countForCard(Card $card): int
    {
        return (int) $this->createQueryBuilder('link')
            ->select('COUNT(link.id)')
            ->where('link.card = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<CardDocument> */
    public function findForDocument(Document $document): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('card', 'column')
            ->join('link.card', 'card')
            ->join('card.column', 'column')
            ->where('link.document = :document')
            ->setParameter('document', $document)
            ->orderBy('link.linkedAt', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<Document> $documents
     *
     * @return list<CardDocument>
     */
    public function findForDocuments(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        return $this->createQueryBuilder('link')
            ->addSelect('card', 'column')
            ->join('link.card', 'card')
            ->join('card.column', 'column')
            ->where('link.document IN (:documents)')
            ->setParameter('documents', $documents)
            ->orderBy('link.linkedAt', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> the ids of the cards linked to the document */
    public function findCardIdsForDocument(Uuid $documentId): array
    {
        /** @var list<array{cardId: Uuid|string}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('DISTINCT IDENTITY(link.card) AS cardId')
            ->where('link.document = :document')
            ->setParameter('document', $documentId, UuidType::NAME)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['cardId'], $rows);
    }

    /**
     * @param list<string> $tagNames
     *
     * @return list<string> the ids of the drafts linked to the card that carry one of the tags
     */
    public function findDraftDocumentIdsWithTagsForCard(Uuid $cardId, array $tagNames): array
    {
        if ([] === $tagNames) {
            return [];
        }

        /** @var list<array{documentId: Uuid|string}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('DISTINCT document.id AS documentId')
            ->join('link.document', 'document')
            ->join('document.tags', 'tag')
            ->where('link.card = :card')
            ->andWhere('document.status = :status')
            ->andWhere('tag.name IN (:tags)')
            ->setParameter('card', $cardId, UuidType::NAME)
            ->setParameter('status', DocumentStatus::Draft)
            ->setParameter('tags', $tagNames)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['documentId'], $rows);
    }

    /**
     * The links of the cards to a document in review and not archived, with the
     * number of its current version. The card column and the document tags come
     * loaded, so a caller can apply the stage rule with no query per row.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<array{link: CardDocument, versionNumber: int, versionAt: ?\DateTimeImmutable}>
     */
    public function findInReviewForCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{0: CardDocument, versionNumber: int|string, versionAt: ?string}> $rows */
        $rows = $this->inReview($project)
            ->join('card.column', 'cardColumn')
            ->leftJoin('document.tags', 'tag')
            ->addSelect('card', 'cardColumn', 'document', 'tag')
            ->addSelect(\sprintf('(SELECT MAX(version.versionNumber) FROM %s version WHERE version.document = document) AS versionNumber', DocumentVersion::class))
            ->addSelect(\sprintf('(SELECT MAX(newest.createdAt) FROM %s newest WHERE newest.document = document) AS versionAt', DocumentVersion::class))
            ->andWhere('card.id IN (:cards)')
            ->setParameter('cards', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds))
            ->orderBy('link.linkedAt', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['link' => $row[0], 'versionNumber' => (int) $row['versionNumber'], 'versionAt' => null === $row['versionAt'] ? null : new \DateTimeImmutable($row['versionAt'])], $rows);
    }

    /**
     * The id, the status, the title, the time and the number of the newest version and the tag names of each unarchived
     * document linked to the card, as the database holds them now, in link order. Each row also holds the entry check of the
     * newest version, the id of its newest review, whether any version ever got a change request or a withdrawal, and whether the
     * newest review of the newest version withdraws an approval.
     *
     * @return list<array{id: string, status: string, title: string, versionAt: ?\DateTimeImmutable, version: ?int, enteredReviewAt: ?\DateTimeImmutable, entryUnansweredDecisions: ?int, entryOpenThreads: ?int, newestReviewId: ?string, rejectedBefore: bool, newestReviewWithdrewApproval: bool, tags: list<string>}>
     */
    public function findStatusesAndTagsForCard(Card $card): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT d.id AS document_id, d.status, d.title, t.name AS tag_name,
                (SELECT MAX(v.created_at) FROM document_versions v WHERE v.document_id = d.id) AS version_at,
                (SELECT MAX(v.version_number) FROM document_versions v WHERE v.document_id = d.id) AS version_number,
                newest.entered_review_at, newest.entry_unanswered_decisions, newest.entry_open_threads,
                (SELECT r.id FROM reviews r WHERE r.version_id = newest.id ORDER BY r.sequence DESC LIMIT 1) AS newest_review_id,
                (SELECT r.verdict = \'withdrawn\' AND COALESCE((SELECT p.verdict FROM reviews p WHERE p.version_id = r.version_id AND p.sequence = r.sequence - 1), \'\') = \'approved\'
                    FROM reviews r WHERE r.version_id = newest.id ORDER BY r.sequence DESC LIMIT 1) AS newest_review_withdrew_approval,
                EXISTS (
                    SELECT 1 FROM reviews r JOIN document_versions rv ON rv.id = r.version_id
                    WHERE rv.document_id = d.id AND r.verdict IN (\'changes-requested\', \'withdrawn\')
                ) AS rejected_before
             FROM board_card_documents cd
             JOIN documents d ON d.id = cd.document_id
             LEFT JOIN LATERAL (
                 SELECT v.id, v.entered_review_at, v.entry_unanswered_decisions, v.entry_open_threads
                 FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_number DESC LIMIT 1
             ) newest ON TRUE
             LEFT JOIN document_tags dt ON dt.document_id = d.id
             LEFT JOIN tags t ON t.id = dt.tag_id
             WHERE cd.card_id = :card AND d.archived_at IS NULL
             ORDER BY cd.linked_at, d.id, t.name',
            ['card' => (string) $card->id],
        );
        $platform = $connection->getDatabasePlatform();
        $type = Type::getType(Types::DATETIME_IMMUTABLE);

        $documents = [];
        foreach ($rows as $row) {
            $documentId = (string) $row['document_id'];
            $entered = $type->convertToPHPValue($row['entered_review_at'], $platform);
            $documents[$documentId] ??= [
                'id' => $documentId,
                'status' => (string) $row['status'],
                'title' => (string) $row['title'],
                'versionAt' => null === $row['version_at'] ? null : new \DateTimeImmutable((string) $row['version_at']),
                'version' => null === $row['version_number'] ? null : (int) $row['version_number'],
                'enteredReviewAt' => $entered instanceof \DateTimeImmutable ? $entered : null,
                'entryUnansweredDecisions' => null === $row['entry_unanswered_decisions'] ? null : (int) $row['entry_unanswered_decisions'],
                'entryOpenThreads' => null === $row['entry_open_threads'] ? null : (int) $row['entry_open_threads'],
                'newestReviewId' => null === $row['newest_review_id'] ? null : (string) $row['newest_review_id'],
                'rejectedBefore' => (bool) $row['rejected_before'],
                'newestReviewWithdrewApproval' => (bool) $row['newest_review_withdrew_approval'],
                'tags' => [],
            ];
            if (null !== $row['tag_name']) {
                $documents[$documentId]['tags'][] = (string) $row['tag_name'];
            }
        }

        return array_values($documents);
    }

    private function inReview(Project $project): QueryBuilder
    {
        return $this->createQueryBuilder('link')
            ->join('link.card', 'card')
            ->join('link.document', 'document')
            ->andWhere('card.project = :project')
            ->andWhere('document.status = :status')
            ->andWhere('document.archivedAt IS NULL')
            ->setParameter('project', $project)
            ->setParameter('status', DocumentStatus::InReview);
    }
}
