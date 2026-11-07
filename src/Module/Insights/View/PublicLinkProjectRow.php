<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** A project with public links, as the admin list shows it. */
final readonly class PublicLinkProjectRow
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public string $ownerId,
        public string $ownerName,
        public string $ownerEmail,
        /** The links of the project, or the one link a code search found. */
        public int $items,
        /** The links that a visitor could open if no takedown stood. */
        public int $liveItems,
        /** A code that opens one link of the project. The take down page takes it. */
        public string $code,
        public ?\DateTimeImmutable $takenDownAt,
        public ?string $takenDownByEmail,
    ) {
    }

    public function isTakenDown(): bool
    {
        return null !== $this->takenDownAt;
    }
}
