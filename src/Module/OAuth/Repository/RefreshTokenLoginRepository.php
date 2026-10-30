<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Reads and writes the login tags of refresh tokens with plain SQL, next to the token rows they point at. */
final readonly class RefreshTokenLoginRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Tags the new token with the login of the token it replaces. A first
     * issue, and a replaced token that has no tag, mint a new login.
     */
    public function tag(string $refreshTokenId, ?string $replacedId): void
    {
        $loginId = null === $replacedId ? null : $this->connection->fetchOne(
            'SELECT login_id FROM oauth_refresh_token_login WHERE refresh_token_identifier = :id',
            ['id' => $replacedId],
        );

        $this->connection->executeStatement(
            'INSERT INTO oauth_refresh_token_login (refresh_token_identifier, login_id) VALUES (:token, :login) ON CONFLICT (refresh_token_identifier) DO NOTHING',
            ['token' => $refreshTokenId, 'login' => false === $loginId || null === $loginId ? Uuid::v7()->toRfc4122() : (string) $loginId],
        );
    }

    public function loginOfAccessToken(string $accessTokenId): ?Uuid
    {
        $loginId = $this->connection->fetchOne(
            'SELECT l.login_id FROM oauth2_refresh_token r
             JOIN oauth_refresh_token_login l ON l.refresh_token_identifier = r.identifier
             WHERE r.access_token = :id',
            ['id' => $accessTokenId],
        );

        return false === $loginId ? null : Uuid::fromString((string) $loginId);
    }

    /**
     * @param list<string> $loginIds RFC 4122 login ids
     *
     * @return list<string> the ids among them that hold at least one live refresh token
     */
    public function live(array $loginIds): array
    {
        if ([] === $loginIds) {
            return [];
        }

        /** @var list<string> $live */
        $live = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT l.login_id FROM oauth_refresh_token_login l
             JOIN oauth2_refresh_token r ON r.identifier = l.refresh_token_identifier
             WHERE l.login_id IN (:logins) AND r.revoked = false AND r.expiry > NOW()',
            ['logins' => $loginIds],
            ['logins' => ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $id): string => (string) $id, $live);
    }
}
