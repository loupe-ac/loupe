<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;

final readonly class DownloadArtifactCommand
{
    public function __construct(
        public Artifact $artifact,
        /** Null for the latest version. */
        public ?int $versionNumber = null,
    ) {
    }
}
