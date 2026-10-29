<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Reads and writes the rotation rows and the token rows they point at, with
 * plain SQL, so a read sees the state a row lock waited for and never a copy
 * that the entity manager cached earlier.
 */
final readonly class RefreshTokenRotationRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** Takes the row lock of a refresh token. It does nothing outside a transaction. */
    public function lock(string $refreshTokenId): void
    {
        if (!$this->connection->isTransactionActive()) {
            return;
        }

        $this->connection->fetchOne('SELECT identifier FROM oauth2_refresh_token WHERE identifier = :id FOR UPDATE', ['id' => $refreshTokenId]);
    }

    /** @return array{successor: string, rotatedAt: \DateTimeImmutable}|null */
    public function find(string $previousId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT successor_identifier, rotated_at FROM oauth_refresh_token_rotation WHERE previous_identifier = :id',
            ['id' => $previousId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'successor' => trim((string) $row['successor_identifier']),
            'rotatedAt' => new \DateTimeImmutable((string) $row['rotated_at']),
        ];
    }

    /** The first call writes the time. Later calls replace the successor only. */
    public function record(string $previousId, string $successorId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO oauth_refresh_token_rotation (previous_identifier, successor_identifier, rotated_at)
             VALUES (:previous, :successor, :now)
             ON CONFLICT (previous_identifier) DO UPDATE SET successor_identifier = EXCLUDED.successor_identifier',
            ['previous' => $previousId, 'successor' => $successorId, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function isRevoked(string $refreshTokenId): bool
    {
        return false === $this->connection->fetchOne(
            'SELECT 1 FROM oauth2_refresh_token WHERE identifier = :id AND revoked = false',
            ['id' => $refreshTokenId],
        );
    }

    /** Revokes a refresh token and the access token it was issued with. */
    public function revokePair(string $refreshTokenId): void
    {
        $this->connection->executeStatement(
            'UPDATE oauth2_access_token SET revoked = true
             WHERE identifier = (SELECT access_token FROM oauth2_refresh_token WHERE identifier = :id)',
            ['id' => $refreshTokenId],
        );
        $this->connection->executeStatement('UPDATE oauth2_refresh_token SET revoked = true WHERE identifier = :id', ['id' => $refreshTokenId]);
    }
}
