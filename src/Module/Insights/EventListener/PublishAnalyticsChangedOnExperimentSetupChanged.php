<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\ExperimentSetupChanged;
use App\Module\Insights\Service\AnalyticsArea;
use App\Module\Insights\Service\AnalyticsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class PublishAnalyticsChangedOnExperimentSetupChanged
{
    public function __construct(
        private AnalyticsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(ExperimentSetupChanged $event): void
    {
        $this->publisher->changed(Uuid::fromString($event->projectId), AnalyticsArea::EXPERIMENTS, $event->experiment);
    }
}
