<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Mcp\AdvertisedTools;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;

/** The tools this instance advertises, and the machines of the project owner, for the Connect page. */
final readonly class ShowConnectHandler
{
    public function __construct(
        private AdvertisedTools $advertisedTools,
        private WorkshopConnectionsProviderInterface $connections,
    ) {
    }

    public function __invoke(ShowConnectCommand $command): ShowConnectView
    {
        return new ShowConnectView(
            $this->advertisedTools->enabled(),
            $this->connections->forOwnerOf($command->project),
        );
    }
}
