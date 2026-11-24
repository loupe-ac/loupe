<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\BridgeCardWorkerWaits;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Workflow\Contract\CardSnapshot;
use Psr\Clock\ClockInterface;

final readonly class WorkerWaitFactProvider extends BridgeFactProvider
{
    public function __construct(
        private BridgeCardWorkerWaits $waits,
        private WorkerWaitRepository $workerWaits,
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WorkerWaitFacts::class;
    }

    #[\Override]
    public function sourceTables(): array
    {
        return [
            'bridge_worker_runs',
            'bridge_worker_waits',
            'bridges',
        ];
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'worker-waits';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new WorkerWaitFacts($this->waits->openFor($card->id), $this->strandedOf($card));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WorkerWaitFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return [
            null === $facts->wait ? null : [(string) $facts->wait->id, $facts->wait->limitAt->format('U')],
            null === $facts->stranded ? null : [(string) $facts->stranded->id, $facts->stranded->bridgeQuiet],
        ];
    }

    private function strandedOf(CardSnapshot $card): ?StrandedWait
    {
        $wait = $this->workerWaits->findStrandedOfCard($card->id);
        if (null === $wait) {
            return null;
        }
        $seenAt = $this->bridges->findLastSeenAt($wait->bridgeId);
        $quiet = null === $seenAt || $seenAt < $this->liveness->quietBefore($this->clock->now());

        return new StrandedWait($wait->id ?? throw new \LogicException('A stored wait has an id.'), $quiet, $quiet || null === $seenAt ? null : $this->liveness->quietAt($seenAt));
    }
}
