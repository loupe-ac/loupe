<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Project\Entity\Project;

/**
 * Resolves artifact ids to the project's artifacts, refusing anything else.
 *
 * Called before a handler opens its transaction, for the reason given in
 * DocumentLinkResolver.
 */
final readonly class ArtifactLinkResolver
{
    public function __construct(
        private ArtifactRepository $artifacts,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Artifact>
     */
    public function resolve(Project $project, array $ids): array
    {
        $artifacts = [];
        $seen = [];
        foreach ($ids as $id) {
            $id = trim($id);
            if ('' === $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $artifact = $this->artifacts->findOneByIdInProject($id, $project)
                ?? throw new DomainErrors(['artifactIds' => 'board.card.error.artifact_unknown']);

            $artifacts[] = $artifact;
        }

        return $artifacts;
    }
}
