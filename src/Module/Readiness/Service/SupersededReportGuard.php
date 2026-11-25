<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Contract\VerdictGuardInterface;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;

/** Refuses each verdict on the report of a discovery run that a newer run replaced, so no card comes from an old report. */
final readonly class SupersededReportGuard implements VerdictGuardInterface
{
    public const string SUPERSEDED = 'readiness.report.error.superseded';

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private ProjectRepository $projects,
    ) {
    }

    #[\Override]
    public function refusal(Document $document, Verdict $verdict): ?string
    {
        if (null === $this->discoveryRuns->findStateOfReport($document)) {
            return null;
        }
        // A start locks the project row FOR UPDATE, which waits for this lock. A FOR UPDATE lock here would wait for the key share of an engine pass, which can wait for this verdict.
        $this->projects->lockForWrite($document->project);

        return DiscoveryRunState::Superseded === $this->discoveryRuns->findStateOfReport($document) ? self::SUPERSEDED : null;
    }
}
