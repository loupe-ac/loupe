<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Doctrine\Type\MicrosecondDateTimeImmutableType;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Security\ApiKeyActor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<CardEvent> */
class CardEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardEvent::class);
    }

    /**
     * Persists and does not flush: every caller writes inside a transaction
     * that flushes the row together with the change it records.
     *
     * @param array<string, mixed> $detail
     */
    public function record(Card $card, CardEventKind $kind, Actor $actorKind, ?User $actorUser, array $detail, ?\DateTimeImmutable $at = null, ?ApiKeyActor $apiKey = null): CardEvent
    {
        $event = new CardEvent($card, $card->project, $kind, $actorKind, $actorUser, $detail, $at ?? new \DateTimeImmutable(), apiKeyId: $apiKey?->keyId, apiKeyName: $apiKey?->keyName);
        $this->getEntityManager()->persist($event);

        return $event;
    }

    /**
     * Writes the `run-finished` row of a run, or gives the row the outcome of a later close.
     * It goes through DBAL in its own transaction, a savepoint inside a caller's,
     * so a failed write leaves the entity manager and the caller's transaction usable.
     * A write that read an older state change than the row holds changes nothing,
     * and so does a write for a run that left the state it records, such as a reopen.
     * The share lock waits for an open change to the run, then reads its new state.
     *
     * @param array<string, mixed> $detail with the `runId` and the `state`
     */
    public function upsertRunFinished(Card $card, Uuid $runId, ?User $actorUser, array $detail, \DateTimeImmutable $at): void
    {
        $this->getEntityManager()->getConnection()->transactional(static function (Connection $connection) use ($card, $runId, $actorUser, $detail, $at): void {
            // The project first, as a run report locks it, so the run lock below cannot deadlock with one.
            $connection->executeQuery('SELECT 1 FROM projects WHERE id = ?::uuid FOR KEY SHARE', [(string) $card->project->id]);
            $connection->executeStatement(
                'INSERT INTO board_card_events (id, card_id, project_id, kind, actor_kind, actor_user_id, detail, occurred_at, run_id)
             SELECT ?::uuid, ?::uuid, ?::uuid, ?, ?, ?::uuid, ?::jsonb, ?::timestamp, r.id
             FROM bridge_worker_runs r WHERE r.id = ?::uuid AND r.state = ? FOR SHARE OF r
             ON CONFLICT (card_id, run_id) DO UPDATE SET detail = EXCLUDED.detail, occurred_at = EXCLUDED.occurred_at
             WHERE board_card_events.detail IS DISTINCT FROM EXCLUDED.detail
               AND COALESCE((EXCLUDED.detail->>\'stateSequence\')::bigint, 0) >= COALESCE((board_card_events.detail->>\'stateSequence\')::bigint, 0)',
                [
                    Uuid::v7()->toRfc4122(),
                    (string) $card->id,
                    (string) $card->project->id,
                    CardEventKind::RunFinished->value,
                    Actor::Agent->value,
                    $actorUser?->id?->toRfc4122(),
                    $detail,
                    $at,
                    $runId->toRfc4122(),
                    $detail['state'] ?? null,
                ],
                [6 => Types::JSON, 7 => MicrosecondDateTimeImmutableType::NAME],
            );
        });
    }

    /** Removes the row of a run that reopened, unless the row holds a newer state change than the reopen. */
    public function deleteRunFinished(Card $card, Uuid $runId, ?int $stateSequence): void
    {
        $this->getEntityManager()->getConnection()->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(
            "DELETE FROM board_card_events WHERE card_id = ?::uuid AND run_id = ?::uuid
               AND COALESCE((detail->>'stateSequence')::bigint, 0) <= ?",
            [(string) $card->id, $runId->toRfc4122(), $stateSequence ?? \PHP_INT_MAX],
        ));
    }

    /** @return list<CardEvent> newest first, older than the given row when one is given, with the actor loaded */
    public function findPageBeforeForCard(Card $card, ?\DateTimeImmutable $beforeAt, ?string $beforeId, int $limit): array
    {
        $query = $this->createQueryBuilder('e')
            ->addSelect('u')
            ->leftJoin('e.actorUser', 'u')
            ->andWhere('e.card = :card')
            ->setParameter('card', $card)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $beforeAt && null !== $beforeId) {
            $query
                ->andWhere('e.occurredAt < :beforeAt OR (e.occurredAt = :beforeAt AND e.id < :beforeId)')
                ->setParameter('beforeAt', $beforeAt, MicrosecondDateTimeImmutableType::NAME)
                ->setParameter('beforeId', Uuid::fromString($beforeId), UuidType::NAME);
        }

        return array_values($query->getQuery()->getResult());
    }

    /** @return list<CardEvent> newest first */
    public function findForCard(Card $card): array
    {
        return array_values($this->findBy(['card' => $card], ['occurredAt' => 'DESC', 'id' => 'DESC']));
    }

    /** @return list<CardEvent> newest first, each with its actor loaded */
    public function findPageForCard(Card $card, int $offset, int $limit): array
    {
        return array_values($this->createQueryBuilder('e')
            ->leftJoin('e.actorUser', 'u')
            ->addSelect('u')
            ->andWhere('e.card = :card')
            ->setParameter('card', $card)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    public function countForCard(Card $card): int
    {
        return $this->count(['card' => $card]);
    }

    /**
     * Each of the project's cards with these ids, with its rows of the kinds.
     * A card with no such row comes back once with a null kind. Rows come oldest first per card.
     *
     * @param list<Uuid>          $cardIds
     * @param list<CardEventKind> $kinds
     *
     * @return list<array{cardId: string, kind: ?CardEventKind, detail: array<mixed>, at: ?\DateTimeImmutable}>
     */
    public function findKindsOfCards(Project $project, array $cardIds, array $kinds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        /** @var list<array{card_id: string, kind: ?string, detail: ?string, occurred_at: ?string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT c.id AS card_id, e.kind, e.detail, e.occurred_at
            FROM board_cards c
            LEFT JOIN board_card_events e ON e.card_id = c.id AND e.kind IN (:kinds)
            WHERE c.project_id = :project AND c.id IN (:cards)
            ORDER BY c.id, e.occurred_at, e.id',
            [
                'project' => ($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122(),
                'cards' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
                'kinds' => array_map(static fn (CardEventKind $kind): string => $kind->value, $kinds),
            ],
            ['cards' => ArrayParameterType::STRING, 'kinds' => ArrayParameterType::STRING],
        );

        $type = Type::getType(Types::DATETIME_IMMUTABLE);
        $platform = $connection->getDatabasePlatform();

        return array_map(static function (array $row) use ($type, $platform): array {
            $detail = null === $row['detail'] ? [] : json_decode($row['detail'], true, flags: \JSON_THROW_ON_ERROR);
            $at = $type->convertToPHPValue($row['occurred_at'], $platform);

            return [
                'cardId' => $row['card_id'],
                'kind' => null === $row['kind'] ? null : CardEventKind::from($row['kind']),
                'detail' => \is_array($detail) ? $detail : [],
                'at' => $at instanceof \DateTimeImmutable ? $at : null,
            ];
        }, $rows);
    }

    /**
     * @param list<string> $cardIds RFC 4122 ids
     *
     * @return array<string, \DateTimeImmutable> card id => the time of its newest history row; a card with none has no key
     */
    public function findNewestOccurredAtOfCards(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform();
        $type = Type::getType(Types::DATETIME_IMMUTABLE);

        $newest = [];
        /** @var list<array{card_id: string, newest: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT card_id, MAX(occurred_at) AS newest FROM board_card_events WHERE card_id IN (:cards) GROUP BY card_id',
            ['cards' => $cardIds],
            ['cards' => ArrayParameterType::STRING],
        );
        foreach ($rows as $row) {
            $at = $type->convertToPHPValue($row['newest'], $platform);
            if ($at instanceof \DateTimeImmutable) {
                $newest[$row['card_id']] = $at;
            }
        }

        return $newest;
    }

    /**
     * @param list<string> $cardIds RFC 4122 ids
     *
     * @return array<string, \DateTimeImmutable> card id => the time of its newest column move; a card never moved has no key
     */
    public function findNewestMoveAtOfCards(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform();
        $type = Type::getType(Types::DATETIME_IMMUTABLE);

        $newest = [];
        /** @var list<array{card_id: string, newest: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT card_id, MAX(occurred_at) AS newest FROM board_card_events WHERE card_id IN (:cards) AND kind = :kind GROUP BY card_id',
            ['cards' => $cardIds, 'kind' => CardEventKind::Moved->value],
            ['cards' => ArrayParameterType::STRING],
        );
        foreach ($rows as $row) {
            $at = $type->convertToPHPValue($row['newest'], $platform);
            if ($at instanceof \DateTimeImmutable) {
                $newest[$row['card_id']] = $at;
            }
        }

        return $newest;
    }

    /** The time of the newest `created` or `moved` row of the card, which is its arrival in its current column. Null when it has none. */
    public function findNewestArrivalAt(Uuid $cardId): ?\DateTimeImmutable
    {
        $connection = $this->getEntityManager()->getConnection();
        $newest = $connection->fetchOne(
            'SELECT MAX(occurred_at) FROM board_card_events WHERE card_id = :card AND kind IN (:created, :moved)',
            ['card' => $cardId->toRfc4122(), 'created' => CardEventKind::Created->value, 'moved' => CardEventKind::Moved->value],
        );
        $at = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($newest, $connection->getDatabasePlatform());

        return $at instanceof \DateTimeImmutable ? $at : null;
    }

    /** The type the card was created with, or null when its `created` row records none. */
    public function createdType(Card $card): ?string
    {
        $type = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT detail->>'type' FROM board_card_events WHERE card_id = :card AND kind = :kind ORDER BY occurred_at, id LIMIT 1",
            ['card' => (string) $card->id, 'kind' => CardEventKind::Created->value],
        );

        return \is_string($type) && '' !== $type ? $type : null;
    }

    public function findFirstOccurredAt(Project $project): ?\DateTimeImmutable
    {
        $connection = $this->getEntityManager()->getConnection();
        $first = $connection->fetchOne(
            'SELECT MIN(occurred_at) FROM board_card_events WHERE project_id = :project',
            ['project' => ($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122()],
        );

        $value = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($first, $connection->getDatabasePlatform());

        return $value instanceof \DateTimeImmutable ? $value : null;
    }
}
