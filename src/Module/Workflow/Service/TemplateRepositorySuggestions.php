<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateMissing;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the suggestions from the stored template copy of the project. */
#[AsAlias(RepositorySuggestions::class)]
final readonly class TemplateRepositorySuggestions implements RepositorySuggestions
{
    public function __construct(
        private ProjectTemplateCopy $templates,
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): array
    {
        try {
            return $this->templates->forProject($projectId)->repositorySettings;
        } catch (TemplateMissing) {
            return [];
        }
    }

    #[\Override]
    public function forProjectFresh(Uuid $projectId): array
    {
        try {
            return $this->templates->forProjectFresh($projectId)->repositorySettings;
        } catch (TemplateMissing) {
            return [];
        }
    }
}
