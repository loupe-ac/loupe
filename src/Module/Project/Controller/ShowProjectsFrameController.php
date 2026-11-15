<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ListProjectsCommand;
use App\Module\Project\Command\ListProjectsHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The first page of the projects list as a frame, which the live region reads when an owned project changes. */
#[Route(
    '/projects-live/frame',
    name: 'app_projects_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ShowProjectsFrameController extends AppController
{
    public function __construct(
        private readonly ListProjectsHandler $listProjects,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->listProjects)(new ListProjectsCommand($user, 1));

        return $this->render('@Project/_projects_frame.html.twig', [
            'items' => $view->items,
            'page' => 1,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
        ]);
    }
}
