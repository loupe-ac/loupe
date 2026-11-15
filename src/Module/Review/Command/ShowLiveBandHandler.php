<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentVersionRepository;

final readonly class ShowLiveBandHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(ShowLiveBandCommand $command): ShowLiveBandView
    {
        $latest = $this->documentVersions->findLatest($command->document)->versionNumber;

        return new ShowLiveBandView(
            archived: null !== $command->document->archivedAt,
            newerVersionNumber: $latest > $command->shownVersionNumber ? $latest : 0,
        );
    }
}
