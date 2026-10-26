<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardSnapshot;

final readonly class HumanReviewFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return HumanReviewFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'human-review';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');

        return new HumanReviewFacts($stored->requiresHumanReview);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof HumanReviewFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->needsPerson;
    }
}
