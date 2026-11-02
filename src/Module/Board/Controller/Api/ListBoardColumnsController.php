<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Board\Command\PageBoardColumnsCommand;
use App\Module\Board\Command\PageBoardColumnsHandler;
use App\Module\Board\Service\BoardColumnPresenter;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Lists the columns in board order, so the list takes no `sort`. The answer carries the card types beside them. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'listBoardColumns',
    summary: 'List the columns and card types of the board',
    tags: ['Columns'],
    description: 'The columns read in board order, so this list takes no `sort`. The answer also carries the card types, which `board_columns` returns beside the columns.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of columns, with the card types.', BoardColumnListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/board/columns',
    name: 'api_board_column_list',
    methods: ['GET'],
)]
final class ListBoardColumnsController extends AppController
{
    public function __construct(
        private readonly PageBoardColumnsHandler $pageColumns,
        private readonly BoardColumnPresenter $presenter,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $view = ($this->pageColumns)(new PageBoardColumnsCommand(
            project: $project,
            page: PageRequest::fromRequest($request, PageBoardColumnsHandler::BOARD_ORDER),
        ));

        return $view->page->toResponse(
            $request,
            $this->presenter->column(...),
            ['types' => $this->presenter->types($view->types), 'defaultType' => $view->types->defaultKey],
        );
    }
}
