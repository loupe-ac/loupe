<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactVersion;

final readonly class ArtifactListItem
{
    public function __construct(
        public Artifact $artifact,
        public ArtifactVersion $latest,
        public int $linkedCardCount,
    ) {
    }
}
