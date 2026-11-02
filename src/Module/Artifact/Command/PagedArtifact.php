<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;

/** One artifact of a page, with the number of its newest version. */
final readonly class PagedArtifact
{
    public function __construct(
        public Artifact $artifact,
        public int $latestVersion,
    ) {
    }
}
