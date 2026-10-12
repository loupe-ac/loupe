<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

final readonly class ArtifactDownload
{
    public function __construct(
        /** A temporary zip file the caller deletes after it sends it. */
        public string $path,
        public string $filename,
    ) {
    }
}
