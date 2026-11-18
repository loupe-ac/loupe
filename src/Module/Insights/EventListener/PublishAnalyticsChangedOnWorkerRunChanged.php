<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Insights\Service\AnalyticsArea;
use App\Module\Insights\Service\AnalyticsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A run that names an experiment changes what the experiment pages show. A run of no experiment signals nothing. */
#[AsEventListener]
final readonly class PublishAnalyticsChangedOnWorkerRunChanged
{
    public function __construct(
        private AnalyticsChangedPublisher $publisher,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $experiments = [];
        foreach ($this->workerRuns->findByIds($event->runIds) as $run) {
            if (null !== $run->experiment) {
                $experiments[$run->experiment] = true;
            }
        }

        ksort($experiments, \SORT_STRING);
        foreach (array_keys($experiments) as $experiment) {
            $this->publisher->changed($event->projectId, AnalyticsArea::EXPERIMENTS, (string) $experiment);
        }
    }
}
