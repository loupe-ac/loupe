<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\CardStateHints;
use App\Module\Workflow\Contract\FactProvider;

/** The engine stamps the work hold on its rule states, so it gives this fact itself. */
final readonly class WorkHoldFactProvider implements FactProvider
{
    public function __construct(
        private CardStateHints $hints,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WorkHoldFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new WorkHoldFacts($this->hints->workHoldsSince([$card->id->toRfc4122()])[$card->id->toRfc4122()] ?? null);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WorkHoldFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return null !== $facts->since;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
