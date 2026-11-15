<?php

declare(strict_types=1);

namespace App\Module\Artifact\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** An artifact page passes this topic to mercure_subscribe(). */
final class ArtifactTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProjectTopicBuilder $topics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('artifacts_topic', $this->artifactsTopic(...)),
        ];
    }

    public function artifactsTopic(Project $project): string
    {
        return $this->topics->forArtifacts($project->id ?? throw new \LogicException('Project has no id.'));
    }
}
