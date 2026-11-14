<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ListBacklogRowsCommand;
use App\Module\Board\Command\ListBacklogRowsHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\View\BacklogListQuery;
use App\Module\Board\View\LiveCursor;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The live read of a Backlog page after the first: a stream that morphs the
 * rows the page shows, removes the rows that left, and counts the new cards.
 * The row order never changes, so a reader keeps their place.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/backlog/rows',
    name: 'app_project_board_backlog_rows',
    requirements: ['projectId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ListBacklogRowsController extends AppController
{
    private const int MAX_ROWS = 100;

    public function __construct(
        private readonly ListBacklogRowsHandler $listBacklogRows,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findBacklogForProjectId(projectId)')] BoardColumn $backlog,
    ): Response {
        $since = LiveCursor::parse($request->query->getString('since'));
        if (null === $since) {
            throw $this->createNotFoundException('The read needs the moment the page rendered.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => strtolower(trim($id)), explode(',', $request->query->getString('ids'))),
            static fn (string $id): bool => Uuid::isValid($id),
        )));
        $listQuery = BacklogListQuery::fromQuery($request->query);

        $view = ($this->listBacklogRows)(new ListBacklogRowsCommand($backlog, $listQuery, \array_slice($ids, 0, self::MAX_ROWS), $since));

        return new Response($this->renderView('@Board/_backlog_rows.stream.html.twig', [
            'project' => $project,
            'view' => $view,
            'listQuery' => $listQuery,
        ]), Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
