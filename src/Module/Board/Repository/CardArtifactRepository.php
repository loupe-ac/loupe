<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

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
}
