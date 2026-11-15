<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\ExperimentUnarchived;
use App\Module\Insights\Service\AnalyticsArea;
use App\Module\Insights\Service\AnalyticsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class PublishAnalyticsChangedOnExperimentUnarchived
{
    public function __construct(
        private AnalyticsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(ExperimentUnarchived $event): void
    {
        $this->publisher->changed($event->project->requireId(), AnalyticsArea::EXPERIMENTS, $event->experiment);
    }
}
