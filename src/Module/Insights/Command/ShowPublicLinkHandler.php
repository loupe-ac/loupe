<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Insights\Preview\PublicPreviewVersion;
use App\Module\Insights\Service\InsightsProjectActivities;
use App\Module\Insights\Service\PublicExperimentReport;
use App\Module\Insights\Service\PublicGraphResults;
use App\Module\Insights\Service\PublicLinkDescription;
use App\Module\Insights\Service\PublicLinkVisibility;
use App\Module\Insights\View\PublicLinkDetailView;
use App\Module\Review\Service\MarkdownRenderer;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Reads the shared item behind a code for a visitor. Every reason to hide it throws the same not found. */
final readonly class ShowPublicLinkHandler
{
    public function __construct(
        private PublicLinkVisibility $visibility,
        private PublicGraphResults $graphResults,
        private PublicExperimentReport $experimentReport,
        private MarkdownRenderer $markdown,
        private InsightsProjectActivities $activities,
        private PublicLinkDescription $descriptions,
        private ClockInterface $clock,
    ) {
    }

    /** @throws NotFoundHttpException when the code shows nothing */
    public function __invoke(ShowPublicLinkCommand $command): PublicLinkDetailView
    {
        $link = $this->visibility->resolve($command->code) ?? throw new NotFoundHttpException('No public link shows for this code.');
        $this->activities->recordView((string) $link->project->requireId());
        if (PublicLinkKind::Experiment === $link->kind && null !== $link->experiment) {
            $result = $this->experimentReport->forExperiment($link->project, $link->experiment) ?? throw new NotFoundHttpException('No public link shows for this code.');
            $note = $link->note;
            $noteHtml = null === $note || '' === trim($note) ? null : $this->markdown->renderWithoutHeadingIds($note);

            return PublicLinkDetailView::forExperiment($link->project, $result, $noteHtml, $link->code, PublicPreviewVersion::of($result, $this->clock->now()), $this->descriptions->forExperiment($result, $noteHtml, $link->project->name));
        }

        $graph = $link->savedGraph;
        if (PublicLinkKind::Graph !== $link->kind || null === $graph) {
            throw new NotFoundHttpException('No public link shows for this code.');
        }

        $kept = $this->graphResults->kept($graph);
        $result = null === $kept ? null : $this->graphResults->view($link->project, $kept);

        return PublicLinkDetailView::forGraph($link->project, $graph, $result, $link->code, PublicPreviewVersion::of($kept, $this->clock->now(), $graph->showLegend), $this->descriptions->forGraph($graph));
    }
}
