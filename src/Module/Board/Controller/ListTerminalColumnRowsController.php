<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ListTerminalColumnRowsCommand;
use App\Module\Board\Command\ListTerminalColumnRowsHandler;
use App\Module\Board\Entity\BoardColumn;
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
 * The live read of the open terminal column page: a stream that morphs the
 * rows the page shows and removes the rows that left the column. Page one
 * inserts the rows that entered it, and a later page counts the cards that
 * finished since. The rows the page shows never change order.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/terminal/{columnId}/rows',
    name: 'app_project_board_terminal_column_rows',
    requirements: ['projectId' => Requirement::UUID, 'columnId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ListTerminalColumnRowsController extends AppController
{
    private const int MAX_ROWS = 100;

    public function __construct(
        private readonly ListTerminalColumnRowsHandler $listTerminalColumnRows,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(columnId, projectId)')] BoardColumn $column,
    ): Response {
        if (!$column->terminal) {
            throw $this->createNotFoundException();
        }
        $page = max(1, $request->query->getInt('page', 1));
        $since = LiveCursor::parse($request->query->getString('since'));
        if (null === $since && $page > 1) {
            throw $this->createNotFoundException('The read needs the moment the page rendered.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => strtolower(trim($id)), explode(',', $request->query->getString('ids'))),
            static fn (string $id): bool => Uuid::isValid($id),
        )));
        $view = ($this->listTerminalColumnRows)(new ListTerminalColumnRowsCommand($column, \array_slice($ids, 0, self::MAX_ROWS), $since, $page));

        return new Response($this->renderView('@Board/_terminal_rows.stream.html.twig', [
            'project' => $project,
            'column' => $column,
            'view' => $view,
            'routeParams' => ['projectId' => (string) $project->id, 'columnId' => (string) $column->id],
        ]), Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
