<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

/** One tag of the vocabulary, as the spec shows it. */
#[OA\Schema(required: ['name', 'documentCount'])]
final readonly class TagRowResponse
{
    public function __construct(
        #[OA\Property(description: 'The tag, as it is stored: lower case, with the surrounding spaces cut off.')]
        public string $name,

        #[OA\Property(description: 'How many documents carry the tag. Archived documents count, so the number can exceed what the document list shows.', minimum: 0)]
        public int $documentCount,
    ) {
    }
}
