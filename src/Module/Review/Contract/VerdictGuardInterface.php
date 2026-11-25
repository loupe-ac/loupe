<?php

declare(strict_types=1);

namespace App\Module\Review\Contract;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Runs in the verdict transaction, with the project and document rows locked. A lock it takes holds until commit.
 * That transaction also commits after a refusal, so a guard changes no entity.
 */
#[AutoconfigureTag(self::TAG)]
interface VerdictGuardInterface
{
    public const string TAG = 'app.review.verdict_guard';

    /** The translation key of the refusal, or null to let the verdict through. The key needs no placeholder and an English message. */
    public function refusal(Document $document, Verdict $verdict): ?string;
}
