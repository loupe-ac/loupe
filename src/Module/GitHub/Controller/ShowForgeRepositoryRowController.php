<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\GitHub\Command\ShowRepositoryRowCommand;
use App\Module\GitHub\Command\ShowRepositoryRowHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * One repository row as a Turbo stream, which the Repositories page fetches
 * after a live signal. A removed repository answers 404.
 */
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/repositories/{repositoryId}/row',
    name: 'app_github_repository_row',
    requirements: ['repositoryId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowForgeRepositoryRowController extends AppController
{
    public function __construct(
        private readonly ShowRepositoryRowHandler $showRepositoryRow,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        // The alias moves the raw project id to `project`, and scoping to it makes a foreign id a 404.
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(repositoryId, project)')] ForgeRepository $repository,
    ): Response {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
        }

        $row = ($this->showRepositoryRow)(new ShowRepositoryRowCommand($repository));

        return new Response(
            $this->renderView('@GitHub/_repository_row.stream.html.twig', ['row' => $row, 'project' => $project]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
