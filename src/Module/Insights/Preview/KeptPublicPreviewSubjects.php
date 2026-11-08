<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Insights\Service\PublicExperimentReport;
use App\Module\Insights\Service\PublicGraphResults;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Reads the same result as the share page does, so the image never computes data of its own. */
#[AsAlias(PublicPreviewSubjects::class)]
final readonly class KeptPublicPreviewSubjects implements PublicPreviewSubjects
{
    public function __construct(
        private PublicGraphResults $graphResults,
        private PublicExperimentReport $experimentReport,
    ) {
    }

    #[\Override]
    public function read(PublicLink $link): ?PublicPreviewSubject
    {
        if (PublicLinkKind::Experiment === $link->kind) {
            $result = null === $link->experiment ? null : $this->experimentReport->forExperiment($link->project, $link->experiment);

            return null === $result ? null : new PublicPreviewSubject(PublicPreviewVersion::of($result), experiment: $result);
        }
        $graph = $link->savedGraph;
        if (null === $graph) {
            return null;
        }
        $kept = $this->graphResults->kept($graph);

        return new PublicPreviewSubject(PublicPreviewVersion::of($kept, $graph->showLegend), $kept, $graph->showLegend);
    }
}
