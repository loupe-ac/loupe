<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\ProjectsReadingFacts;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(ProjectsReadingFacts::class)]
final readonly class TemplateProjectsReadingFacts implements ProjectsReadingFacts
{
    public function __construct(
        private ProjectTemplates $templates,
    ) {
    }

    #[\Override]
    public function among(array $projectIds, string $factsClass): array
    {
        return array_values(array_filter(
            $projectIds,
            fn (string $projectId): bool => $this->templates->of(Uuid::fromString($projectId))->readsFacts($factsClass),
        ));
    }
}
