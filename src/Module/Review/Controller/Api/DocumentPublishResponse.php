<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Module\Review\Entity\DocumentStatus;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['documentId', 'status', 'published'])]
final readonly class DocumentPublishResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $documentId,

        #[OA\Property]
        public DocumentStatus $status,

        #[OA\Property(description: 'False when the document was not a draft.')]
        public bool $published,
    ) {
    }
}
