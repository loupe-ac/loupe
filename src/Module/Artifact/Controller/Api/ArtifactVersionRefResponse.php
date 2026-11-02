<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use OpenApi\Attributes as OA;

/** A version in the row of an artifact list: its number and the time it was stored, without the notes, the counts and the sizes. */
#[OA\Schema(required: ['number', 'storedAt'])]
final readonly class ArtifactVersionRefResponse
{
    public function __construct(
        public int $number,

        #[OA\Property(format: 'date-time')]
        public string $storedAt,
    ) {
    }
}
