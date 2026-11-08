<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\BridgeAccountReadingsStored;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsRefreshRequester;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class RefreshInsightsOnBridgeAccountReadingsStored
{
    public function __construct(
        private InsightsRefreshRequester $requester,
    ) {
    }

    public function __invoke(BridgeAccountReadingsStored $event): void
    {
        foreach ($event->projectIds as $projectId) {
            $this->requester->projectKind($projectId, InsightsDataKind::Readings);
        }
    }
}
