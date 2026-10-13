<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class PauseFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardPauseRepository $cardPauses,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return PauseFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $pause = $this->cardPauses->findActiveForCard($this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.'));

        return new PauseFacts(null === $pause ? null : BoardCardPauses::view($pause));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof PauseFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->active?->id->toRfc4122();
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
