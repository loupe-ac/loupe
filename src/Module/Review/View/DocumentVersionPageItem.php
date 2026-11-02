<?php

declare(strict_types=1);

namespace App\Module\Review\View;

/** One row of a page of versions: the metadata and the thread count, never the Markdown. */
final readonly class DocumentVersionPageItem
{
    public function __construct(
        public string $id,
        public int $versionNumber,
        public \DateTimeImmutable $createdAt,
        public ?string $description,
        public int $threadCount,
    ) {
    }
}
