<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

/** What a page of artifact rows must read: each flag stands for one batch of database reads. */
final readonly class ArtifactRowReads
{
    /**
     * @param bool $selectedVersion the number of the latest version
     * @param bool $versions        the versions, as number and date
     * @param bool $wholeVersions   the versions with their notes, counts and sizes
     * @param bool $files           the files of the latest version, as paths
     * @param bool $wholeFiles      the files with their content type and size
     * @param bool $pageLink        the signed link of the entry file of the latest version
     */
    public function __construct(
        public bool $selectedVersion = true,
        public bool $versions = true,
        public bool $wholeVersions = false,
        public bool $files = true,
        public bool $wholeFiles = false,
        public bool $pageLink = false,
    ) {
    }
}
