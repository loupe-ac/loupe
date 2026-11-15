<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\BucketTimesRecomputed;
use App\Module\Insights\Service\AnalyticsArea;
use App\Module\Insights\Service\AnalyticsChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class PublishAnalyticsChangedOnBucketTimesRecomputed
{
    public function __construct(
        private AnalyticsChangedPublisher $publisher,
    ) {
    }

    public function __invoke(BucketTimesRecomputed $event): void
    {
        $this->publisher->changed(Uuid::fromString($event->projectId), AnalyticsArea::BUCKET_RULES);
    }
}
