<?php

declare(strict_types=1);

namespace App\Module\Artifact\Event;

use Symfony\Component\Uid\Uuid;

/**
 * A version of an artifact was stored, or the artifact was archived or
 * restored. Dispatched after the commit, with ids only. The version number is
 * set for a stored version and null for an archive state change.
 */
final readonly class ArtifactChanged
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $artifactId,
        public ArtifactChange $change,
        public ?int $versionNumber = null,
    ) {
    }
}
