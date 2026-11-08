<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Names a graph whose result the worker must not keep. Only a dev seed holds one, so its tile keeps waiting. */
#[AutoconfigureTag('app.insights_refresh_hold')]
interface InsightsRefreshHold
{
    public function holds(string $graphId): bool;
}
