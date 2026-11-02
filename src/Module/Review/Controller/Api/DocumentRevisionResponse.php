<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['url', 'versionNumber', 'carried', 'orphaned'])]
final readonly class DocumentRevisionResponse
{
    public function __construct(
        #[OA\Property(description: 'The review page of the document in the app.', format: 'uri')]
        public string $url,

        #[OA\Property(description: 'The number of the new version.')]
        public int $versionNumber,

        #[OA\Property(description: 'The open comments carried to the new version.')]
        public int $carried,

        #[OA\Property(description: 'The comments whose quoted text no longer appears.')]
        public int $orphaned,
    ) {
    }
}
