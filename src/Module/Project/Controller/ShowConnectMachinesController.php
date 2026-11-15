<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Command\ShowConnectCommand;
use App\Module\Project\Command\ShowConnectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The machines frame of the Connect page, which the live region reads when a worker run changes. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/connect/machines',
    name: 'app_project_connect_machines',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ShowConnectMachinesController extends AppController
{
    public function __construct(
        private readonly ShowConnectHandler $showConnect,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $connect = ($this->showConnect)(new ShowConnectCommand($project));

        return $this->render('@Project/_connect_machines_frame.html.twig', [
            'project' => $project,
            'machines' => $connect->machines,
        ]);
    }
}
