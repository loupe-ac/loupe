<?php

declare(strict_types=1);

namespace App\Module\Artifact\Twig;

use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The sidebar pill of the Artifacts item, which counts what the library shows by default. */
final class ArtifactNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly ArtifactRepository $artifacts,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('project_artifact_count', $this->artifactCount(...))];
    }

    public function artifactCount(Project $project): int
    {
        return $this->artifacts->countInProject($project, false);
    }
}
