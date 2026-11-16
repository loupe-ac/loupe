<?php

declare(strict_types=1);

namespace App\Outbox\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Outbox\Command\ListActivityPageHandler;
use App\Outbox\Command\ListActivityRowsCommand;
use App\Outbox\Command\ListActivityRowsHandler;
use App\Outbox\View\ActivityListQuery;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

/** The rows of a later activity page, refreshed by id when a signal says the project recorded an event. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/activity/rows',
    name: 'app_project_activity_rows',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ListActivityRowsController extends AppController
{
    public function __construct(
        private readonly ListActivityRowsHandler $listRows,
    ) {
    }

    public function __invoke(
        Project $project,
        Request $request,
        #[MapQueryParameter] string $ids = '',
        #[MapQueryParameter] int $since = 0,
    ): Response {
        $listQuery = ActivityListQuery::fromQuery($request->query);
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_project_activity', ['id' => (string) $project->id, ...$listQuery->routeParams()]);
        }

        $shown = array_values(array_filter(
            explode(',', $ids),
            static fn (string $id): bool => Uuid::isValid($id),
        ));
        $view = ($this->listRows)(new ListActivityRowsCommand(
            $project,
            $listQuery,
            \array_slice($shown, 0, ListActivityPageHandler::MAX_PER_PAGE),
            max(0, $since),
        ));

        return new Response(
            $this->renderView('outbox/_activity_rows.stream.html.twig', [
                'project' => $project,
                'view' => $view,
                'listQuery' => $listQuery,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
