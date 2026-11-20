<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The live frame of the column settings page, which the open page reads again after a column change. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/columns/frame',
    name: 'app_board_settings_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowBoardSettingsFrameController extends AppController
{
    public function __construct(
        private readonly ShowBoardHandler $showBoard,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('@Board/_board_settings_frame.html.twig', [
            'board' => ($this->showBoard)(new ShowBoardCommand($project)),
            'addColumnForm' => null,
            'configureColumnForm' => null,
            'terminalWindowForm' => null,
        ]);
    }
}
