<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Insights\Event\AnalysisChanged;
use App\Module\Insights\Service\AnalyticsArea;
use App\Module\Insights\Service\AnalyticsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class PublishAnalyticsChangedOnAnalysisChanged
{
    public function __construct(
        private AnalyticsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(AnalysisChanged $event): void
    {
        $this->publisher->changed(Uuid::fromString($event->projectId), AnalyticsArea::REPORTS, $event->analysisId);
    }
}
