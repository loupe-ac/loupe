<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\ValueObject\NamedAccountReading;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class AccountUsageFactProvider implements FactProvider
{
    public function __construct(
        private BridgeAccountReadingRepository $bridgeAccountReadings,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return AccountUsageFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new AccountUsageFacts($this->bridgeAccountReadings->latestNamedOfProjectOwner($card->projectId));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof AccountUsageFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::sorted(array_map(
            static fn (NamedAccountReading $reading): array => [$reading->bridgeName, $reading->account, $reading->window, $reading->usedPercent, $reading->resetsAt->getTimestamp()],
            $facts->readings,
        ));
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.bridge';
    }
}
