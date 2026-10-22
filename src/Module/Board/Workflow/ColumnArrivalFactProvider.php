<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardEventRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class ColumnArrivalFactProvider implements FactProvider
{
    public function __construct(
        private CardEventRepository $cardEvents,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return ColumnArrivalFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new ColumnArrivalFacts($this->cardEvents->findNewestArrivalAt($card->id));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof ColumnArrivalFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->arrivedAt?->format(\DATE_ATOM);
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
