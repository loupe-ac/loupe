<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Board\Command\FindBoardColumnBySlugCommand;
use App\Module\Board\Command\FindBoardColumnBySlugHandler;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Board\Service\BoardColumnApiProblems;
use App\Module\Board\Service\BoardColumnPresenter;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getColumn',
    summary: 'Read one column',
    tags: ['Columns'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'column', description: 'The slug of a column, from the column list.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'The column.', ColumnWriteResponse::class),
        new ProblemResponse(404, ['project_not_found', 'column_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/board/columns/{column}',
    name: 'api_board_column_show',
    methods: ['GET'],
)]
final class ShowBoardColumnController extends AppController
{
    public function __construct(
        private readonly FindBoardColumnBySlugHandler $findColumn,
        private readonly ListBoardColumnsHandler $listColumns,
        private readonly BoardColumnPresenter $presenter,
        private readonly BoardColumnApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $column): JsonResponse
    {
        $found = ($this->findColumn)(new FindBoardColumnBySlugCommand($project, $column)) ?? throw $this->problems->columnNotFound();
        $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

        return new JsonResponse(new ColumnWriteResponse($found->slug, $this->presenter->columns($view->columns)));
    }
}
