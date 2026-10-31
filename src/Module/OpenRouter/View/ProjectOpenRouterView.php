<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\View;

use App\Module\Project\Entity\Project;

/**
 * What the project section shows. The key in use is the project key, else the
 * account key of the owner. The account key is set whenever one exists, so the
 * page can say that removing the project key brings it back.
 */
final readonly class ProjectOpenRouterView
{
    public function __construct(
        public Project $project,
        public ?OpenRouterKeySummary $inUse,
        public ?OpenRouterKeySummary $accountKey,
    ) {
    }

    public function usesProjectKey(): bool
    {
        return true === $this->inUse?->forProject;
    }
}
