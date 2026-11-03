<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['id', 'url'])]
final readonly class CommentReplyResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(description: 'The review page of the document in the app.', format: 'uri')]
        public string $url,
    ) {
    }
}
