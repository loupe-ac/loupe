<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\View\DocumentVersionDetail;

final readonly class FindDocumentVersionHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
    ) {
    }

    /** Null when the document has no such version. */
    public function __invoke(FindDocumentVersionCommand $command): ?DocumentVersionDetail
    {
        $version = $this->documentVersions->findByNumber($command->document, $command->versionNumber);
        if (null === $version) {
            return null;
        }

        $id = (string) $version->id;

        return new DocumentVersionDetail($version, ($this->comments->signalsByVersions([$id])[$id] ?? null)?->threadCount() ?? 0);
    }
}
