<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Asks every tagged hold whether the worker must keep no result for a graph. */
final readonly class InsightsRefreshHolds
{
    /** @param iterable<InsightsRefreshHold> $holds */
    public function __construct(
        #[AutowireIterator('app.insights_refresh_hold')]
        private iterable $holds,
    ) {
    }

    public function holds(string $graphId): bool
    {
        foreach ($this->holds as $hold) {
            if ($hold->holds($graphId)) {
                return true;
            }
        }

        return false;
    }
}
