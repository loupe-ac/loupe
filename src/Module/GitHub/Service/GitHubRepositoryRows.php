<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Service\RepositoryChecks;
use App\Module\GitHub\View\RepositoryGuide;
use App\Module\GitHub\View\RepositoryRow;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\WorkflowKeys;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Builds the rows of the Repositories section for the page and for the live row route. */
final readonly class GitHubRepositoryRows
{
    public function __construct(
        private ForgeRepositoryCheckRepository $forgeRepositoryChecks,
        private RepositoryChecks $checks,
        private RepositorySuggestions $suggestions,
        private WorkflowKeys $workflowKeys,
        private GitHubRepositoryCheckLines $lines,
        private GitHubRepositoryGuide $guide,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param bool $requestCheck true for a page render, which queues a check when the last read is stale. The live row route passes false, so it never draws "Checking…".
     */
    public function forRepository(ForgeRepository $repository, bool $requestCheck): RepositoryRow
    {
        $projectId = $repository->project->id ?? throw new \LogicException('A persisted project has an id.');
        $suggests = [] !== $this->suggestions->forProject($projectId);
        $check = $suggests ? $this->forgeRepositoryChecks->findOneOfRepository($repository) : null;
        if (!$suggests && $requestCheck) {
            $this->checks->requestStaleItemsClear($repository);
        }

        return new RepositoryRow(
            $repository,
            $repository->health($this->clock->now())->value,
            $this->workflowLabelKey($this->workflowKeys->forProject($projectId)),
            $check,
            null === $check ? [] : $this->lines->forCheck($check),
            $suggests,
            $suggests && $requestCheck && $this->checks->requestPageOpenCheck($repository),
            null === $check ? new RepositoryGuide([]) : $this->guide->forCheck($check),
        );
    }

    private function workflowLabelKey(?string $workflowKey): ?string
    {
        if (null === $workflowKey) {
            return null;
        }

        $key = 'workflow.template.'.$workflowKey.'.label';

        return $key === $this->translator->trans($key) ? null : $key;
    }
}
