<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardSnapshot;

final readonly class EpicBranchSyncFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return EpicBranchSyncFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'epic-branch-sync';
    }

    /** The epic branch is in sync when no blocker from outside the epic finished after the last merge of the default branch into it. */
    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');
        $epic = $stored->parent;
        if (null === $epic) {
            return new EpicBranchSyncFacts(true);
        }

        $blockers = $this->cards->findDoneBlockersOutsideEpicOf($stored);
        if ([] === $blockers) {
            return new EpicBranchSyncFacts(true);
        }

        $syncedAt = $epic->epicBranchSyncedAt;
        if (null === $syncedAt) {
            return new EpicBranchSyncFacts(false);
        }
        foreach ($blockers as $blocker) {
            if (null !== $blocker->completedAt && $blocker->completedAt >= $syncedAt) {
                return new EpicBranchSyncFacts(false);
            }
        }

        return new EpicBranchSyncFacts(true);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof EpicBranchSyncFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->synced;
    }
}
