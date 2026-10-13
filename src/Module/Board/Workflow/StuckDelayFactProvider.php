<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\BoardSettings;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class StuckDelayFactProvider implements FactProvider
{
    public function __construct(
        private BoardSettings $settings,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return StuckDelayFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new StuckDelayFacts($this->settings->stuckDelayMinutes($card->projectId));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof StuckDelayFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->minutes;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
