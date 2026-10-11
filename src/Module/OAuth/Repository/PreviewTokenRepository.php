<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

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

    /** Reads the card table by SQL, because OAuth must not depend on Board. */
    public function cardBelongsToProject(Uuid $cardId, Project $project): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM board_cards WHERE id = :cardId AND project_id = :projectId',
            ['cardId' => $cardId->toRfc4122(), 'projectId' => (string) $project->id],
        );
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

    public function revokeByAccessTokenId(string $accessTokenId): int
    {
        return $this->revokeWhere('p.access_token_id = :accessTokenId', ['accessTokenId' => $accessTokenId]);
    }

    public function revokeByCard(Uuid $cardId): int
    {
        return $this->revokeWhere('p.card_id = :cardId', ['cardId' => $cardId->toRfc4122()]);
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
