<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardArtifact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CardArtifact> */
class CardArtifactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardArtifact::class);
    }

    /**
     * The links of the cards in link order, each with the number of the newest
     * version of its artifact, in one query.
     *
     * @param list<Card> $cards
     *
     * @return array<string, list<array{link: CardArtifact, versionNumber: int}>> keyed by card id; a card with no link has no key
     */
    public function findWithLatestVersionForCards(array $cards): array
    {
        if ([] === $cards) {
            return [];
        }

        /** @var list<array{0: CardArtifact, versionNumber: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->addSelect('artifact')
            ->addSelect(\sprintf('(SELECT MAX(version.number) FROM %s version WHERE version.artifact = artifact) AS versionNumber', ArtifactVersion::class))
            ->join('link.artifact', 'artifact')
            ->where('link.card IN (:cards)')
            ->setParameter('cards', $cards)
            ->orderBy('link.linkedAt', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byCard = [];
        foreach ($rows as $row) {
            $byCard[(string) $row[0]->card->id][] = ['link' => $row[0], 'versionNumber' => (int) $row['versionNumber']];
        }

        return $byCard;
    }

    /**
     * @param list<Artifact> $artifacts
     *
     * @return array<string, int> keyed by artifact id; an artifact with no link is absent
     */
    public function countByArtifacts(array $artifacts): array
    {
        if ([] === $artifacts) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('IDENTITY(link.artifact) AS id, COUNT(link.id) AS total')
            ->where('link.artifact IN (:artifacts)')
            ->setParameter('artifacts', $artifacts)
            ->groupBy('link.artifact')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return list<CardArtifact> with the card loaded, in card number order */
    public function findByArtifact(Artifact $artifact): array
    {
        return array_values($this->createQueryBuilder('link')
            ->addSelect('card')
            ->join('link.card', 'card')
            ->where('link.artifact = :artifact')
            ->setParameter('artifact', $artifact)
            ->orderBy('card.number', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
