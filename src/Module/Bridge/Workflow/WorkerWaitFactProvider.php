<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Service\BridgeCardWorkerWaits;
use App\Module\Workflow\Contract\CardSnapshot;

final readonly class WorkerWaitFactProvider extends BridgeFactProvider
{
    public function __construct(
        private BridgeCardWorkerWaits $waits,
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
            'bridge_worker_waits',
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
        return new WorkerWaitFacts($this->waits->openFor($card->id));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WorkerWaitFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return null === $facts->wait ? null : [(string) $facts->wait->id, $facts->wait->limitAt->format('U')];
    }
}
