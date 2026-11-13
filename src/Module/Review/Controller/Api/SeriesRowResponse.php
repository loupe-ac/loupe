<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

/** One series, as the spec shows it. */
#[OA\Schema(required: ['name', 'documentCount', 'highestOrdinal'])]
final readonly class SeriesRowResponse
{
    public function __construct(
        #[OA\Property(description: 'The name, as its first document spelled it or a rename wrote it.')]
        public string $name,

        #[OA\Property(description: 'How many documents the series holds. Archived documents count.', minimum: 0)]
        public int $documentCount,

        #[OA\Property(description: 'The highest position in use, which says what the next free number is. Null for a series that no document belongs to.', nullable: true, minimum: 1)]
        public ?int $highestOrdinal,
    ) {
    }
}
