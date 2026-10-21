<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class DocumentFacts
{
    /**
     * @param list<string>        $tags
     * @param ?\DateTimeImmutable $versionAt the time of the newest version, null with no version
     * @param ?int                $version   the number of the newest version, null with no version
     */
    public function __construct(
        public array $tags,
        public string $status,
        public string $id,
        public ?string $title = null,
        public ?\DateTimeImmutable $versionAt = null,
        public ?int $version = null,
    ) {
    }

    public function withStatus(string $status): self
    {
        return new self($this->tags, $status, $this->id, $this->title, $this->versionAt, $this->version);
    }
}
