<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Module\GitHub\Command\ShowInitHandoffCommand;
use App\Module\GitHub\Command\ShowInitHandoffHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The page that sends the owner back to the terminal after an install that `loupe init` started.
 * The two success paths of the picker redirect here and name the repositories they added. A visit
 * that names none the project holds goes to the Repositories tab.
 */
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/init-handoff',
    name: 'app_github_init_handoff',
    methods: ['GET'],
)]
class ShowInitHandoffController extends AppController
{
    public function __construct(
        private readonly ShowInitHandoffHandler $showHandoff,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $repositories = ($this->showHandoff)(new ShowInitHandoffCommand($project, $request->query->getString('repository')));
        if ([] === $repositories) {
            return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
        }

        return $this->render('@GitHub/show_init_handoff.html.twig', ['projectId' => (string) $project->id, 'repositories' => $repositories]);
    }
}
