<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Service\MergedPullRequestReviews;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\EpicBranches;

final readonly class HumanReviewFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequests $cardPullRequests,
        private EpicBranches $epicBranches,
        private MergedPullRequestReviews $reviews,
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

        return new HumanReviewFacts($stored->requiresHumanReview || $this->childrenNeedPerson($stored));
    }

    /** An epic needs a person when a child has its switch on, or when a child pull request merged into the epic branch without a passed or overridden review. */
    private function childrenNeedPerson(Card $epic): bool
    {
        if (0 === $this->cards->childProgressOf($epic)['total']) {
            return false;
        }
        if ($this->cards->hasChildRequiringHumanReview($epic)) {
            return true;
        }
        $epicBranch = $this->epicBranches->of($epic->project->requireId(), $epic->number);

        return null !== $epicBranch && $this->reviews->hasUnreviewed($this->cardPullRequests->childrenMergedInto($epic, $epicBranch));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof HumanReviewFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->needsPerson;
    }
}
