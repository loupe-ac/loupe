<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CardValue> */
class CardValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardValue::class);
    }

    /** @return list<CardValue> every value the card holds, by key */
    public function findForCard(Card $card): array
    {
        return array_values($this->findBy(['card' => $card], ['key' => 'ASC']));
    }

    /**
     * @param list<Card> $cards
     *
     * @return array<string, list<CardValue>> the values of each card by key, keyed by card id; a card with none has no key
     */
    public function findForCards(array $cards): array
    {
        if ([] === $cards) {
            return [];
        }

        /** @var list<CardValue> $rows */
        $rows = $this->createQueryBuilder('v')
            ->leftJoin('v.writerUser', 'writer')
            ->addSelect('writer')
            ->where('v.card IN (:cards)')
            ->setParameter('cards', $cards)
            ->orderBy('v.key', 'ASC')
            ->getQuery()
            ->getResult();

        $byCard = [];
        foreach ($rows as $row) {
            $byCard[(string) $row->card->id][] = $row;
        }

        return $byCard;
    }

    public function findOneForCard(Card $card, string $key): ?CardValue
    {
        return $this->findOneBy(['card' => $card, 'key' => $key]);
    }

    /**
     * Reads the row as the database holds it, also when the request loaded it before.
     * Call it under the project lock, so a write decides against the committed value.
     */
    public function findOneForCardFresh(Card $card, string $key): ?CardValue
    {
        $row = $this->createQueryBuilder('v')
            ->where('v.card = :card')
            ->andWhere('v.key = :key')
            ->setParameter('card', $card)
            ->setParameter('key', $key)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row instanceof CardValue ? $row : null;
    }

    /** Persists and does not flush: the caller's transaction flushes the row with the change it records. */
    public function store(CardValue $value): void
    {
        $this->getEntityManager()->persist($value);
    }
}
