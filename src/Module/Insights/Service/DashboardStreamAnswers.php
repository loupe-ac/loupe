<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Command\ShowDashboardCommand;
use App\Module\Insights\Command\ShowDashboardGraphCommand;
use App\Module\Insights\Command\ShowDashboardGraphHandler;
use App\Module\Insights\Command\ShowDashboardHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/** The answers that every Dashboard tile action gives: a Turbo Stream of the grid, of one tile, or a flash. */
final readonly class DashboardStreamAnswers
{
    public function __construct(
        private ShowDashboardHandler $showDashboard,
        private ShowDashboardGraphHandler $showGraph,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    /** The grid, and the Undo notice of `$removed` or none. */
    public function grid(Project $project, ?InsightsSavedGraph $removed = null): Response
    {
        $view = ($this->showDashboard)(new ShowDashboardCommand($project));

        return $this->stream($this->twig->render('@Insights/_dashboard_change.stream.html.twig', ['project' => $view->project, 'view' => $view, 'removed' => $removed]));
    }

    public function tile(Project $project, InsightsSavedGraph $graph): Response
    {
        return $this->stream($this->twig->render('@Insights/_dashboard_tile.stream.html.twig', [
            'project' => $project,
            'tile' => ($this->showGraph)(new ShowDashboardGraphCommand($graph)),
        ]));
    }

    /** A refused action: the page stays as it is, and a flash says why. The 422 makes the client undo its own change. */
    public function refused(string $message): Response
    {
        return $this->stream($this->twig->render('_flash.stream.html.twig', ['severity' => 'error', 'message' => $message]), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function invalid(): Response
    {
        return $this->refused($this->translator->trans('analytics.dashboard.flash.invalid'));
    }

    private function stream(string $html, int $status = Response::HTTP_OK): Response
    {
        return new Response($html, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
