<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use OpenApi\Attributes as OA;

/** One file of an artifact version. DescribeArtifactHandler builds the real body. */
#[OA\Schema(required: ['path', 'contentType', 'size'])]
final readonly class ArtifactFileResponse
{
    public function __construct(
        public string $path,
        public string $contentType,
        public int $size,
    ) {
    }
}
