<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\CheckBoardAutomationSettingsCommand;
use App\Module\Board\Command\CheckBoardAutomationSettingsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The fingerprint of the saved settings, which an open form compares with its own after a reconnect. */
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/automation/check',
    name: 'app_board_automation_settings_check',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class CheckBoardAutomationSettingsController extends AppController
{
    public function __construct(
        private readonly CheckBoardAutomationSettingsHandler $checkSettings,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return new Response(($this->checkSettings)(new CheckBoardAutomationSettingsCommand($project)), Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
