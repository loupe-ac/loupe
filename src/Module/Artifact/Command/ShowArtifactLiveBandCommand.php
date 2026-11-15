<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;

final readonly class ShowArtifactLiveBandCommand
{
    public function __construct(
        public Artifact $artifact,
        /** The version number the open page shows. */
        public int $shownVersion,
        /** Whether the open page rendered the artifact as archived. */
        public bool $shownArchived,
    ) {
    }
}
