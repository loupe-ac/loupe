<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Service\DraftReviewLoops;

/** Reads what the band of a draft shows. */
final readonly class ShowDraftStateHandler
{
    public function __construct(
        private DraftReviewLoops $draftReviewLoops,
    ) {
    }

    /** @return ?ShowDraftStateResult null when the document is no longer a draft */
    public function __invoke(ShowDraftStateCommand $command): ?ShowDraftStateResult
    {
        if (DocumentStatus::Draft !== $command->document->status) {
            return null;
        }

        return new ShowDraftStateResult($this->draftReviewLoops->of($command->document));
    }
}
