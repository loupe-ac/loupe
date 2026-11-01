<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ChooseAnalyticsLandingCommand;
use App\Module\Insights\Command\ChooseAnalyticsLandingHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The Analytics link: the Dashboard when it holds a graph, else Metrics. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics',
    name: 'app_project_analytics',
    methods: ['GET'],
)]
class ShowAnalyticsController extends AppController
{
    public function __construct(
        private readonly ChooseAnalyticsLandingHandler $chooseLanding,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->redirectToRoute(($this->chooseLanding)(new ChooseAnalyticsLandingCommand($project)), ['id' => (string) $project->id]);
    }
}
