<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use OpenApi\Attributes as OA;

/** One stored version of an artifact. DescribeArtifactHandler builds the real body. */
#[OA\Schema(required: ['number', 'storedAt', 'storedInSession', 'notes', 'fileCount', 'totalBytes'])]
final readonly class ArtifactVersionResponse
{
    public function __construct(
        public int $number,

        #[OA\Property(format: 'date-time')]
        public string $storedAt,
        public bool $storedInSession,

        #[OA\Property(description: 'What changed.', nullable: true)]
        public ?string $notes,
        public int $fileCount,
        public int $totalBytes,
    ) {
    }
}
