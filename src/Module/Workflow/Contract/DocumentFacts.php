<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class DocumentFacts
{
    /**
     * @param list<string>        $tags
     * @param ?\DateTimeImmutable $versionAt                    the time of the newest version, null with no version
     * @param ?int                $version                      the number of the newest version, null with no version
     * @param ?int                $cleanCheckVersion            the number of the version that the clean entry check covers, null with no clean check
     * @param ?string             $newestReviewId               the id of the newest review of the newest version, null with no review
     * @param ?\DateTimeImmutable $publishedAt                  when the document was published with its newest version, null when that version has no entry check
     * @param ?int                $entryUnansweredDecisions     the decisions with no answer in the entry check, null with no entry check
     * @param ?int                $entryOpenThreads             the threads that were not resolved in the entry check, null with no entry check
     * @param bool                $rejectedBefore               whether any version of the document ever got a change request or a withdrawal
     * @param bool                $newestReviewWithdrewApproval whether the newest review of the newest version is a withdrawal of an approval
     */
    public function __construct(
        public array $tags,
        public string $status,
        public string $id,
        public ?string $title = null,
        public ?\DateTimeImmutable $versionAt = null,
        public ?int $version = null,
        public ?int $cleanCheckVersion = null,
        public ?string $newestReviewId = null,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?int $entryUnansweredDecisions = null,
        public ?int $entryOpenThreads = null,
        public bool $rejectedBefore = false,
        public bool $newestReviewWithdrewApproval = false,
    ) {
    }

    public function hasEntryCheck(): bool
    {
        return null !== $this->publishedAt && null !== $this->entryUnansweredDecisions && null !== $this->entryOpenThreads;
    }
}
