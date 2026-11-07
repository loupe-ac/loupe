<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\BucketTimesRecomputed;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsRefreshRequester;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class RefreshInsightsOnBucketTimesRecomputed
{
    public function __construct(
        private InsightsRefreshRequester $requester,
    ) {
    }

    public function __invoke(BucketTimesRecomputed $event): void
    {
        $this->requester->projectKind($event->projectId, InsightsDataKind::BucketTimes);
    }
}
