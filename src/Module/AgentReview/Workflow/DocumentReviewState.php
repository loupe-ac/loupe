<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;

/** The review loop of one draft design document of a card, as the stored reviews show it. */
final readonly class DocumentReviewState
{
    /**
     * @param list<string> $tags           the tags of the document
     * @param int          $versionNumber  the current version of the document
     * @param ?string      $reviewId       the latest review of the document for the card, or null when it has none
     * @param ?int         $reviewVersion  the version that review read
     * @param bool         $answered       whether a fix round answered that review
     * @param bool         $needsPerson    whether that review has a finding that needs a person
     * @param int          $failedCount    the failed reviews of the document for the card
     * @param int          $ownerPassCount the passed reviews of the document for the card with a finding for the owner
     */
    public function __construct(
        public string $documentId,
        public array $tags,
        public int $versionNumber,
        public ?string $reviewId,
        public ?int $reviewVersion,
        public ?AgentReviewConclusion $conclusion,
        public bool $answered,
        public bool $needsPerson,
        public int $failedCount,
        public int $ownerPassCount = 0,
    ) {
    }

    /** The rounds the loop used: each failed review and each pass with a finding for the owner. */
    public function usedRounds(): int
    {
        return $this->failedCount + $this->ownerPassCount;
    }

    public function hasTag(string $tag): bool
    {
        return \in_array($tag, $this->tags, true);
    }

    public function finished(int $rounds): bool
    {
        if (AgentReviewConclusion::Failure === $this->conclusion) {
            return $this->usedRounds() >= $rounds;
        }

        if (AgentReviewConclusion::Success !== $this->conclusion) {
            return false;
        }

        if (!$this->needsPerson) {
            return $this->reviewVersion === $this->versionNumber;
        }

        return $this->answered && $this->usedRounds() >= $rounds;
    }

    public function due(int $rounds): bool
    {
        if ($this->finished($rounds)) {
            return false;
        }

        return null === $this->reviewVersion || $this->reviewVersion < $this->versionNumber || $this->answered;
    }

    public function fixDue(int $rounds): bool
    {
        if (null === $this->conclusion || $this->answered || $this->reviewVersion < $this->versionNumber) {
            return false;
        }

        return AgentReviewConclusion::Failure === $this->conclusion ? $this->usedRounds() < $rounds : $this->needsPerson;
    }
}
