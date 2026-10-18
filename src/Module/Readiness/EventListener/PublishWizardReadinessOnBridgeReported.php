<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Bridge\Event\BridgeReported;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Readiness\Service\WizardReadinessPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class PublishWizardReadinessOnBridgeReported
{
    public function __construct(
        private ProjectRepository $projects,
        private WizardReadinessPublisher $publisher,
    ) {
    }

    #[AsEventListener]
    public function __invoke(BridgeReported $event): void
    {
        foreach ($event->projectIds as $projectId) {
            $project = $this->projects->find($projectId);
            if (null !== $project) {
                $this->publisher->publishIfChanged($project);
            }
        }
    }
}
