<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Preview\PublicPreviewEntries;
use Doctrine\DBAL\Connection;

/** Counts the kept preview PNGs in the app cache table with one query, and reads the database size. */
final readonly class PublicPreviewUsageReader
{
    private const string IMAGES_SQL = 'SELECT COUNT(*) AS images, COALESCE(SUM(octet_length(item_data)), 0) AS bytes FROM cache_items WHERE item_id LIKE :prefix AND (item_lifetime IS NULL OR item_lifetime + item_time > :now)';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function read(?\DateTimeImmutable $now = null): PublicPreviewUsage
    {
        $now ??= new \DateTimeImmutable();
        $row = $this->connection->fetchAssociative(self::IMAGES_SQL, [
            'prefix' => '%'.addcslashes(PublicPreviewEntries::PNG_PREFIX, '_%\\').'%',
            'now' => $now->getTimestamp(),
        ]);

        return new PublicPreviewUsage(
            (int) ($row['images'] ?? 0),
            (int) ($row['bytes'] ?? 0),
            (int) $this->connection->fetchOne('SELECT pg_database_size(current_database())'),
        );
    }
}
