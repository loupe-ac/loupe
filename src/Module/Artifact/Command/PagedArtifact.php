<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;

/**
 * One artifact of a page with the parts its row asked for. A part the row did not ask for is
 * null or empty.
 *
 * @phpstan-type VersionRef array{number: int, storedAt: string}
 * @phpstan-type VersionWhole array{number: int, storedAt: string, storedInSession: bool, notes: ?string, fileCount: int, totalBytes: int}
 * @phpstan-type FileRef array{path: string}
 * @phpstan-type FileWhole array{path: string, contentType: string, size: int}
 */
final readonly class PagedArtifact
{
    /**
     * @param list<VersionRef|VersionWhole> $versions
     * @param list<FileRef|FileWhole>       $files
     */
    public function __construct(
        public Artifact $artifact,
        public ?int $selectedVersion,
        public array $versions,
        public array $files,
        public ?string $pageLink,
    ) {
    }
}
