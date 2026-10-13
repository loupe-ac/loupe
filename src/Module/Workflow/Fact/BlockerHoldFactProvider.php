<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\CardStateHints;
use App\Module\Workflow\Contract\FactProvider;

/** The engine stamps the hold on its rule states, so it gives this fact itself. */
final readonly class BlockerHoldFactProvider implements FactProvider
{
    public function __construct(
        private CardStateHints $hints,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return BlockerHoldFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new BlockerHoldFacts($this->hints->blockerHolds([$card->id->toRfc4122()])[0] ?? null);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof BlockerHoldFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->hold?->blockerNumber;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
