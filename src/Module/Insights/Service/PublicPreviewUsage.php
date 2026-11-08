<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

/** How much of the app cache the kept preview images use, next to the size of the database. */
final readonly class PublicPreviewUsage
{
    public function __construct(
        public int $images,
        public int $imageBytes,
        public int $databaseBytes,
    ) {
    }
}
