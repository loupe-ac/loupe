<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;

/** Reads the verdict that stands on the latest version, which the top bar names. */
final readonly class ShowStandingVerdictHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private ReviewRepository $reviews,
    ) {
    }

    /** @return ?Review null when no verdict was given, or the last one was withdrawn */
    public function __invoke(ShowStandingVerdictCommand $command): ?Review
    {
        $newest = $this->reviews->findNewestByVersion($this->documentVersions->findLatest($command->document));

        return Verdict::Withdrawn === $newest?->verdict ? null : $newest;
    }
}
