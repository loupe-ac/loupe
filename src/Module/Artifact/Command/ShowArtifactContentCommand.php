<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

final readonly class ShowArtifactContentCommand
{
    public function __construct(
        public string $versionId,
        public string $path,
    ) {
    }
}
