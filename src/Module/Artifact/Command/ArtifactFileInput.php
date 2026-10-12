<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

/** One file of a version to store: its path in the version, its bytes and its content type. */
final readonly class ArtifactFileInput
{
    public function __construct(
        public string $path,
        public string $contents,
        public string $contentType,
    ) {
    }
}
