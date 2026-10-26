<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardSnapshot;

final readonly class ArtifactReachFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return ArtifactReachFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'artifact-reach';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');

        return new ArtifactReachFacts(array_values(array_diff($stored->reachingArtifactIds(), $stored->humanReviewSeenArtifacts)));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof ArtifactReachFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->unseenArtifactIds;
    }
}
