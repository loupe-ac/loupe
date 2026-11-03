<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['verdictId', 'url', 'verdict', 'documentStatus'])]
final readonly class VerdictResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $verdictId,

        #[OA\Property(description: 'The review page of the document in the app.', format: 'uri')]
        public string $url,

        #[OA\Property(enum: ['approved', 'changes-requested'])]
        public string $verdict,

        #[OA\Property(enum: ['in-review', 'approved', 'changes-requested', 'draft'])]
        public string $documentStatus,
    ) {
    }
}
