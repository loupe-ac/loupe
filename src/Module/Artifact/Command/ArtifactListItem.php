<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Service\ArtifactSourceKind;
use App\Module\Artifact\Service\LinkedCardTally;

final readonly class ArtifactListItem
{
    public function __construct(
        public Artifact $artifact,
        public ArtifactVersion $latest,
        /** Null when no card links the artifact. */
        public ?LinkedCardTally $linkedCards,
        public int $fileCount,
        public ?ArtifactSourceKind $source,
    ) {
    }
}
