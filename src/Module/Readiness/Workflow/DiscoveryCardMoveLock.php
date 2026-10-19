<?php

declare(strict_types=1);

namespace App\Module\Readiness\Workflow;

use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Contract\CardMoveLock;
use Symfony\Component\Uid\Uuid;

/** A discovery card stays where it is while a worker writes or revises its report. */
final readonly class DiscoveryCardMoveLock implements CardMoveLock
{
    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
    ) {
    }

    #[\Override]
    public function locks(Uuid $cardId): bool
    {
        return \in_array($this->discoveryRuns->latestForCard($cardId)?->state, [DiscoveryRunState::Requested, DiscoveryRunState::Revising], true);
    }
}
