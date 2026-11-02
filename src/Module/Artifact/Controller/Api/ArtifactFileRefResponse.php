<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use OpenApi\Attributes as OA;

/** A file in the row of an artifact list: its path, without the content type and the size. */
#[OA\Schema(required: ['path'])]
final readonly class ArtifactFileRefResponse
{
    public function __construct(
        public string $path,
    ) {
    }
}
