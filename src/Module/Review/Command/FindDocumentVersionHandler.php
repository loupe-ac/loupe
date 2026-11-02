<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DocumentVersionRepository;

final readonly class FindDocumentVersionHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    /** Null when the document has no such version. */
    public function __invoke(FindDocumentVersionCommand $command): ?DocumentVersion
    {
        return $this->documentVersions->findByNumber($command->document, $command->versionNumber);
    }
}
