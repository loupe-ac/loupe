<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

final readonly class ShowArtifactContentCommand
{
    public function __construct(
        public string $versionId,
        public string $path,
        /** The sub-address the request arrived on, or null on the app domain. */
        public ?string $label = null,
    ) {
    }
}
