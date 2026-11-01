<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\OpenRouter\Service\OpenRouterKeyResolver;
use App\Module\OpenRouter\View\ProjectOpenRouterView;

final readonly class ShowProjectOpenRouterHandler
{
    public function __construct(
        private OpenRouterKeyResolver $resolver,
        private OpenRouterKeyRepository $openRouterKeys,
    ) {
    }

    public function __invoke(ShowProjectOpenRouterCommand $command): ProjectOpenRouterView
    {
        $project = $command->project;
        $inUse = $this->resolver->resolve($project);
        $accountKey = $inUse?->forProject
            ? $this->openRouterKeys->findSummaryForUser($project->owner->id ?? throw new \LogicException('A stored user has an id.'))
            : $inUse;

        return new ProjectOpenRouterView($project, $inUse, $accountKey);
    }
}
