<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use App\Module\OAuth\Command\PreviewTokenRow;
use App\Module\OAuth\Entity\PreviewToken;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PreviewToken> */
class PreviewTokenRepository extends ServiceEntityRepository
{
    /** The longest a use goes unrecorded. */
    public const int LAST_USE_WINDOW_MINUTES = 15;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PreviewToken::class);
    }

    /**
     * Records a use with one conditional UPDATE, which writes only when no use
     * is recorded or the last one is older than the window. A token with no
     * row matches nothing, so it costs no write.
     */
    public function recordUse(string $accessTokenId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE oauth_preview_tokens SET last_used_at = NOW()
             WHERE access_token_id = :id AND (last_used_at IS NULL OR last_used_at < NOW() - make_interval(mins => :window))',
            ['id' => $accessTokenId, 'window' => self::LAST_USE_WINDOW_MINUTES],
        );
    }

    /** @return list<PreviewTokenRow> */
    public function findLiveForUser(Uuid $userId): array
    {
        return $this->findLiveWhere('p.user_id = :userId', ['userId' => $userId->toRfc4122()]);
    }

    /** @return list<PreviewTokenRow> */
    public function findLiveForUserAndCard(Uuid $userId, Uuid $cardId): array
    {
        return $this->findLiveWhere('p.user_id = :userId AND p.card_id = :cardId', [
            'userId' => $userId->toRfc4122(),
            'cardId' => $cardId->toRfc4122(),
        ]);
    }

    /**
     * Whether the card of the project sits in a terminal column, or null when
     * the project has no such card. Reads by SQL, because OAuth must not depend on Board.
     */
    public function isCardFinished(Uuid $cardId, Project $project): ?bool
    {
        $terminal = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT CASE WHEN col.terminal THEN 1 ELSE 0 END FROM board_cards c JOIN board_columns col ON col.id = c.column_id
             WHERE c.id = :cardId AND c.project_id = :projectId',
            ['cardId' => $cardId->toRfc4122(), 'projectId' => (string) $project->id],
        );

        return false === $terminal ? null : 1 === (int) $terminal;
    }

    /**
     * Revokes the live tokens a person minted for a project under one label.
     * It holds the label lock until the enclosing transaction ends, so a mint
     * and a revoke of one label take turns.
     */
    public function revokeByLabel(Uuid $userId, Uuid $projectId, string $label): int
    {
        return $this->getEntityManager()->getConnection()->transactional(function (Connection $connection) use ($userId, $projectId, $label): int {
            $connection->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?))', [self::labelLockKey($userId, $projectId, $label)]);

            return $this->revokeWhere('p.user_id = :userId AND p.project_id = :projectId AND p.label = :label', [
                'userId' => $userId->toRfc4122(),
                'projectId' => $projectId->toRfc4122(),
                'label' => $label,
            ]);
        });
    }

    public static function labelLockKey(Uuid $userId, Uuid $projectId, string $label): string
    {
        return 'preview-token:'.$userId->toRfc4122().':'.$projectId->toRfc4122().':'.$label;
    }

    public function revokeByAccessTokenId(string $accessTokenId, Uuid $userId): int
    {
        return $this->revokeWhere('p.access_token_id = :accessTokenId AND p.user_id = :userId', [
            'accessTokenId' => $accessTokenId,
            'userId' => $userId->toRfc4122(),
        ]);
    }

    public function revokeByCard(Uuid $cardId): int
    {
        return $this->revokeWhere('p.card_id = :cardId', ['cardId' => $cardId->toRfc4122()]);
    }

    /**
     * Reads board_cards by SQL, because OAuth must not depend on Board. A card
     * of another project, or one deleted since, reads as no card.
     *
     * @param array<string, string> $parameters
     *
     * @return list<PreviewTokenRow>
     */
    private function findLiveWhere(string $condition, array $parameters): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT p.access_token_id, p.project_id, pr.name AS project_name, p.card_id, c.number AS card_number, c.title AS card_title,
                    p.label, a.expiry, p.last_used_at, p.created_at
             FROM oauth_preview_tokens p
             JOIN oauth2_access_token a ON a.identifier = p.access_token_id
             JOIN projects pr ON pr.id = p.project_id
             LEFT JOIN board_cards c ON c.id = p.card_id AND c.project_id = p.project_id
             WHERE a.revoked = false AND a.expiry > NOW() AND '.$condition.'
             ORDER BY p.created_at DESC, p.access_token_id',
            $parameters,
        );

        return array_map(static fn (array $row): PreviewTokenRow => new PreviewTokenRow(
            accessTokenId: trim((string) $row['access_token_id']),
            projectId: Uuid::fromString((string) $row['project_id']),
            projectName: (string) $row['project_name'],
            cardId: null === $row['card_id'] ? null : Uuid::fromString((string) $row['card_id']),
            cardNumber: null === $row['card_number'] ? null : (int) $row['card_number'],
            cardTitle: null === $row['card_title'] ? null : (string) $row['card_title'],
            label: (string) $row['label'],
            expiresAt: new \DateTimeImmutable((string) $row['expiry']),
            lastUsedAt: null === $row['last_used_at'] ? null : new \DateTimeImmutable((string) $row['last_used_at']),
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        ), $rows);
    }

    /**
     * Sets revoked on the access token and on any refresh token that points at
     * it. The preview row stays until the access token is purged.
     *
     * @param array<string, string> $parameters
     */
    private function revokeWhere(string $condition, array $parameters): int
    {
        $connection = $this->getEntityManager()->getConnection();

        return $connection->transactional(static function (Connection $connection) use ($condition, $parameters): int {
            $connection->executeStatement(
                'UPDATE oauth2_refresh_token SET revoked = true WHERE revoked = false AND access_token IN
                 (SELECT p.access_token_id FROM oauth_preview_tokens p WHERE '.$condition.')',
                $parameters,
            );

            return (int) $connection->executeStatement(
                'UPDATE oauth2_access_token SET revoked = true WHERE revoked = false AND identifier IN
                 (SELECT p.access_token_id FROM oauth_preview_tokens p WHERE '.$condition.')',
                $parameters,
            );
        });
    }
}
