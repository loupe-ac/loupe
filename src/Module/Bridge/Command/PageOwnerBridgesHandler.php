<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeLiveness;

/** Reads one keyset page of the bridges of an owner, ordered by id. */
final readonly class PageOwnerBridgesHandler
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
    ) {
    }

    public function __invoke(PageOwnerBridgesCommand $command): PageOwnerBridgesView
    {
        $only = $command->onlyProjectId?->toRfc4122();
        $after = $command->after?->toRfc4122();
        $bridges = array_values(array_filter(
            $this->bridges->findByOwnerOrderedById($command->owner),
            static fn (Bridge $bridge): bool => (null === $only || \in_array($only, $bridge->projects, true))
                && (null === $after || strcmp($bridge->id->toRfc4122(), $after) > 0),
        ));
        $hasMore = \count($bridges) > $command->limit;
        $bridges = \array_slice($bridges, 0, $command->limit);

        return new PageOwnerBridgesView(
            $bridges,
            $this->liveness->forOwner($command->owner, array_map(static fn (Bridge $bridge) => $bridge->id, $bridges)),
            $hasMore,
        );
    }
}
