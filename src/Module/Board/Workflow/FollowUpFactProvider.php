<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class FollowUpFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return FollowUpFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');
        ['followed' => $followed, 'open' => $open] = $this->cards->followedCardsOpenCount($stored);

        return new FollowUpFacts($followed > 0, $followed > 0 && 0 === $open);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof FollowUpFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return [$facts->followsAny, $facts->followsOnlyFinished];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
