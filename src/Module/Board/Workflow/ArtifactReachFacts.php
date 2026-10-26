<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

final readonly class ArtifactReachFacts
{
    /** @param list<string> $unseenArtifactIds the artifacts of the card and of its parent that its seen list lacks, in id order */
    public function __construct(
        public array $unseenArtifactIds,
    ) {
    }
}
