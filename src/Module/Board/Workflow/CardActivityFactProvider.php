<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardEventRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class CardActivityFactProvider implements FactProvider
{
    public function __construct(
        private CardEventRepository $cardEvents,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return CardActivityFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $id = $card->id->toRfc4122();

        return new CardActivityFacts($this->cardEvents->findNewestOccurredAtOfCards([$id])[$id] ?? null);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof CardActivityFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->newestEventAt?->format(\DATE_ATOM);
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
