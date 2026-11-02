<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicDelete;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\FindBoardColumnBySlugCommand;
use App\Module\Board\Command\FindBoardColumnBySlugHandler;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardColumnApiProblems;
use App\Module\Board\Service\BoardColumnPresenter;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ReachableProject;
use App\Module\Workflow\Contract\Actor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(BoardColumnVoter::MANAGE, subject: 'project')]
#[PublicDelete(
    operationId: 'deleteBoardColumn',
    summary: 'Delete a column',
    tags: ['Columns'],
    description: 'A moved card follows the rules of a move. Nobody can delete Backlog, and a board keeps at least one terminal column.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'column', description: 'The slug of a column, from the column list.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'targetColumn', description: 'The slug of the column the cards move to. It is needed only when the column holds cards.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'The deleted slug, the moved cards, and every column that remains.', ColumnDeleteResponse::class),
        new ProblemResponse(404, ['project_not_found', 'column_not_found', 'route_not_found']),
        new ProblemResponse(409, ['column_target_required', 'no_terminal_column', 'backlog_locked', 'columns_changed']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/board/columns/{column}',
    name: 'api_board_column_delete',
    methods: ['DELETE'],
)]
final class DeleteBoardColumnController extends AppController
{
    public function __construct(
        private readonly FindBoardColumnBySlugHandler $findColumn,
        private readonly DeleteBoardColumnHandler $deleteColumn,
        private readonly ListBoardColumnsHandler $listColumns,
        private readonly BoardColumnPresenter $presenter,
        private readonly BoardColumnApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, string $column): JsonResponse
    {
        $found = ($this->findColumn)(new FindBoardColumnBySlugCommand($project, $column)) ?? throw $this->problems->columnNotFound();

        $target = null;
        if ($request->query->has('targetColumn')) {
            $slug = $request->query->all()['targetColumn'];
            if (!\is_string($slug)) {
                throw ApiProblemException::validationFailed([['field' => 'targetColumn', 'code' => 'target_column_invalid', 'message' => 'targetColumn must be the slug of a column.']]);
            }
            $target = ($this->findColumn)(new FindBoardColumnBySlugCommand($project, $slug)) ?? throw $this->problems->unknownTargetColumn($slug);
        }

        try {
            $deleted = ($this->deleteColumn)(new DeleteBoardColumnCommand($found, Actor::Human, $target));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }
        $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

        return new JsonResponse(new ColumnDeleteResponse(
            $deleted->slug,
            \count($deleted->movedCardIds),
            $deleted->targetSlug,
            $this->presenter->columns($view->columns),
        ));
    }
}
