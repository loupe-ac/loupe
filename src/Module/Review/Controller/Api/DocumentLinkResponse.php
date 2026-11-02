<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

/** A document that another document points at, or is pointed at by. */
#[OA\Schema(required: ['documentId', 'title', 'archived'])]
final readonly class DocumentLinkResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $documentId,

        #[OA\Property]
        public string $title,

        #[OA\Property]
        public bool $archived,
    ) {
    }
}
