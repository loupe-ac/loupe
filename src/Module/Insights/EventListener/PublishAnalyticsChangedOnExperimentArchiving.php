<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\ExperimentArchiving;
use App\Module\Insights\Service\AnalyticsArea;
use App\Module\Insights\Service\AnalyticsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class PublishAnalyticsChangedOnExperimentArchiving
{
    public function __construct(
        private AnalyticsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(ExperimentArchiving $event): void
    {
        $this->publisher->changed($event->project->requireId(), AnalyticsArea::EXPERIMENTS, $event->experiment);
    }
}
