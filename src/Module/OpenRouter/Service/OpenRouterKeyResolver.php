<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\OpenRouter\View\OpenRouterKeySummary;
use App\Module\Project\Entity\Project;

/** The key that pays for the cost reads of a project: its own, else the account key of its owner. */
final readonly class OpenRouterKeyResolver
{
    public function __construct(
        private OpenRouterKeyRepository $openRouterKeys,
    ) {
    }

    public function resolve(Project $project): ?OpenRouterKeySummary
    {
        return $this->openRouterKeys->findSummaryForProject($project->requireId())
            ?? $this->openRouterKeys->findSummaryForUser($project->owner->id ?? throw new \LogicException('A stored user has an id.'));
    }
}
