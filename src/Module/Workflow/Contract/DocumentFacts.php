<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class DocumentFacts
{
    /**
     * @param list<string>        $tags
     * @param ?\DateTimeImmutable $versionAt the time of the newest version, null with no version
     * @param ?int                $version   the number of the newest version, null with no version
     * @param ?int                $cleanCheckVersion the number of the version that the clean entry check covers, null with no clean check
     * @param ?string             $newestReviewId    the id of the newest review of the newest version, null with no review
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
    ) {
    }

    public function withStatus(string $status): self
    {
        return new self($this->tags, $status, $this->id, $this->title, $this->versionAt, $this->version, $this->cleanCheckVersion, $this->newestReviewId);
    }
}
