<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsRefreshRequester;
use App\Module\Project\Event\ProjectCardsFinishedChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class RefreshInsightsOnProjectCardsFinishedChanged
{
    public function __construct(
        private InsightsRefreshRequester $requester,
    ) {
    }

    public function __invoke(ProjectCardsFinishedChanged $event): void
    {
        $this->requester->projectKind($event->projectId, InsightsDataKind::Cards);
    }
}
