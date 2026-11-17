<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\WorkerRunFactsWritten;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsRefreshRequester;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class RefreshInsightsOnWorkerRunFactsWritten
{
    public function __construct(
        private InsightsRefreshRequester $requester,
    ) {
    }

    public function __invoke(WorkerRunFactsWritten $event): void
    {
        $this->requester->projectKind($event->projectId->toRfc4122(), InsightsDataKind::Runs);
    }
}
