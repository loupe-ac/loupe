<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\WorkerRunFactsWritten;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Insights\Service\AnalyticsArea;
use App\Module\Insights\Service\AnalyticsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The metrics of a run that names an experiment changed what its pages show. A run of no experiment signals nothing. */
#[AsEventListener]
final readonly class PublishAnalyticsChangedOnWorkerRunFactsWritten
{
    public function __construct(
        private AnalyticsChangedPublisher $publisher,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function __invoke(WorkerRunFactsWritten $event): void
    {
        $experiments = [];
        foreach ($this->workerRuns->findByIds($event->runIds) as $run) {
            if (null !== $run->experiment) {
                $experiments[$run->experiment] = true;
            }
        }

        foreach (array_keys($experiments) as $experiment) {
            $this->publisher->changed($event->projectId, AnalyticsArea::EXPERIMENTS, (string) $experiment);
        }
    }
}
