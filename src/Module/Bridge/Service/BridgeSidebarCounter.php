<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Mercure\CountChangeGate;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Sidebar\SidebarCounterInterface;
use Twig\Environment;

/** The Agents pill and the Activity pill of the project sidebar. */
final readonly class BridgeSidebarCounter implements SidebarCounterInterface
{
    public function __construct(
        private CountChangeGate $gate,
        private AgentsFiguresReader $figures,
        private WorkerRunRepository $workerRuns,
        private Environment $twig,
    ) {
    }

    #[\Override]
    public function renderVersions(Project $project, User $viewer): array
    {
        return [self::VERSION_AGENTS => $this->gate->currentVersion(SidebarCountsSignal::versionKey($project)) ?? ''];
    }

    #[\Override]
    public function storedVersions(Project $project, User $viewer): array
    {
        return [self::VERSION_AGENTS => $this->gate->storedVersion(SidebarCountsSignal::versionKey($project)) ?? ''];
    }

    #[\Override]
    public function streams(Project $project, User $viewer): array
    {
        return [$this->twig->render('@Bridge/_sidebar_counts.stream.html.twig', [
            'project' => $project,
            'figures' => $this->figures->forProject($project),
            'openCount' => $this->workerRuns->countOpenOfProject($project),
        ])];
    }
}
