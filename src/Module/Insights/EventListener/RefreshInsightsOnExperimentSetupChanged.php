<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\ExperimentSetupChanged;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsRefreshRequester;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class RefreshInsightsOnExperimentSetupChanged
{
    public function __construct(
        private InsightsRefreshRequester $requester,
    ) {
    }

    public function __invoke(ExperimentSetupChanged $event): void
    {
        $this->requester->projectKind($event->projectId, InsightsDataKind::Experiment, $event->experiment);
    }
}
