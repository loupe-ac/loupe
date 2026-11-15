<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ShowAnalyticsSettingsCommand;
use App\Module\Insights\Command\ShowAnalyticsSettingsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The fingerprint of the analytics settings. The form band compares it with its own after a reconnect. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/reports/settings/fingerprint',
    name: 'app_project_analytics_settings_fingerprint',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class CheckAnalyticsSettingsController extends AppController
{
    public function __construct(
        private readonly ShowAnalyticsSettingsHandler $showSettings,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $settings = ($this->showSettings)(new ShowAnalyticsSettingsCommand($project));

        return new Response($settings->fingerprint, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
