<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Metric\Metric;
use App\Module\Insights\Command\ListReportRowsCommand;
use App\Module\Insights\Command\ListReportRowsHandler;
use App\Module\Insights\Service\LiveRowsQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/** The rows of a later page of the Reports tab, as a Turbo Stream: each shown analysis morphs in place or goes, and the band counts the newer ones. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/reports/rows',
    name: 'app_project_analytics_reports_rows',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ListReportRowsController extends AppController
{
    public function __construct(
        private readonly ListReportRowsHandler $listRows,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $rowsQuery = LiveRowsQuery::fromQuery($request->query);
        $view = ($this->listRows)(new ListReportRowsCommand($project, $rowsQuery->ids, $rowsQuery->since));
        $rows = [];
        foreach ($view->rows as $row) {
            $rows[(string) $row->analysis->id] = $row;
        }

        $html = $this->renderView('@Insights/_report_rows.stream.html.twig', [
            'project' => $project,
            'ids' => array_map(static fn ($id): string => $id->toRfc4122(), $rowsQuery->ids),
            'rows' => $rows,
            'newer' => $view->newer,
            'page' => max(1, $request->query->getInt('page', 1)),
            'costMetric' => Metric::Cost->valueType(),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
