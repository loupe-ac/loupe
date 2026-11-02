<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['id', 'quote', 'body', 'author', 'orphaned'])]
final readonly class ReviewReplyResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property]
        public string $quote,

        #[OA\Property]
        public string $body,

        #[OA\Property(description: 'The class of writer, not an identity.', enum: ['agent', 'human'])]
        public string $author,

        #[OA\Property]
        public bool $orphaned,
    ) {
    }
}
