<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A page that lists the documents of a project passes this topic to mercure_subscribe(). */
final class DocumentTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProjectTopicBuilder $topics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('documents_topic', $this->documentsTopic(...)),
        ];
    }

    public function documentsTopic(Project $project): string
    {
        return $this->topics->forDocuments($project->id ?? throw new \LogicException('Project has no id.'));
    }
}
