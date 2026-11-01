<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ShowDashboardCommand;
use App\Module\Insights\Command\ShowDashboardHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A tile with a kept result draws in the page. A tile without one loads in a lazy frame. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard',
    name: 'app_project_analytics_dashboard',
    methods: ['GET'],
)]
class ShowDashboardController extends AppController
{
    public function __construct(
        private readonly ShowDashboardHandler $showDashboard,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $view = ($this->showDashboard)(new ShowDashboardCommand($project, $request->query->getString('removed') ?: null));

        return $this->render('@Insights/show_dashboard.html.twig', [
            'project' => $view->project,
            'view' => $view,
        ]);
    }
}
