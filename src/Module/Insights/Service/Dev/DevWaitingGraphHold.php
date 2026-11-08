<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Insights\Service\InsightsRefreshHold;
use Symfony\Component\DependencyInjection\Attribute\When;

/** Keeps the seeded waiting graph without a result, so a view and a worker run never turn its tile ready. */
#[When('dev')]
#[When('test')]
final readonly class DevWaitingGraphHold implements InsightsRefreshHold
{
    #[\Override]
    public function holds(string $graphId): bool
    {
        return DevSeedId::of('insights/graph/'.DevMetricsSeeder::WAITING_GRAPH_SLUG)->toRfc4122() === $graphId;
    }
}
