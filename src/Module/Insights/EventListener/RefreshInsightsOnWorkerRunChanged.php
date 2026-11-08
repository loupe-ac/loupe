<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsRefreshRequester;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class RefreshInsightsOnWorkerRunChanged
{
    public function __construct(
        private InsightsRefreshRequester $requester,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $this->requester->projectKind($event->projectId->toRfc4122(), InsightsDataKind::Runs);
    }
}
