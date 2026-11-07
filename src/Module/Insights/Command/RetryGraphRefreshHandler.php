<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Service\InsightsRefreshRequester;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Asks the insights worker to compute one graph again, for the Retry button of a slow tile. */
final readonly class RetryGraphRefreshHandler
{
    public function __construct(
        private InsightsRefreshRequester $requester,
    ) {
    }

    /** @throws NotFoundHttpException when the graph is removed */
    public function __invoke(RetryGraphRefreshCommand $command): void
    {
        if (null !== $command->graph->removedAt) {
            throw new NotFoundHttpException('The project has no such graph.');
        }

        $this->requester->graph($command->graph);
    }
}
