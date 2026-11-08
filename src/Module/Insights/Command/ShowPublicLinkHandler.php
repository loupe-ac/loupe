<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Insights\Service\InsightsProjectActivities;
use App\Module\Insights\Service\PublicExperimentReport;
use App\Module\Insights\Service\PublicGraphResults;
use App\Module\Insights\Service\PublicLinkVisibility;
use App\Module\Insights\View\PublicLinkDetailView;
use App\Module\Review\Service\MarkdownRenderer;
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

            return PublicLinkDetailView::forExperiment($link->project, $result, null === $note || '' === trim($note) ? null : $this->markdown->renderWithoutHeadingIds($note), $link->code);
        }

        $graph = $link->savedGraph;
        if (PublicLinkKind::Graph !== $link->kind || null === $graph) {
            throw new NotFoundHttpException('No public link shows for this code.');
        }

        return PublicLinkDetailView::forGraph($link->project, $graph, $this->graphResults->forGraph($graph), $link->code);
    }
}
