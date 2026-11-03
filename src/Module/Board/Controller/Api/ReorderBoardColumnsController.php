<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPut;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Board\Command\ReorderBoardColumnsBySlugCommand;
use App\Module\Board\Command\ReorderBoardColumnsBySlugHandler;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardColumnApiProblems;
use App\Module\Board\Service\BoardColumnPresenter;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(BoardColumnVoter::MANAGE, subject: 'project')]
#[PublicPut(
    operationId: 'reorderBoardColumns',
    summary: 'Put the columns in a new order',
    tags: ['Columns'],
    description: 'Backlog always comes first and never moves, so leave it out. `errors[].code` is `backlog_in_order`, `unknown_column` or `order_incomplete`.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ReorderBoardColumnsRequest::class))),
    responses: [
        new SuccessResponse(200, 'Every column, in the new order.', ColumnOrderResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['columns_changed']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/board/columns/order',
    name: 'api_board_column_reorder',
    methods: ['PUT'],
)]
final class ReorderBoardColumnsController extends AppController
{
    public function __construct(
        private readonly ReorderBoardColumnsBySlugHandler $reorderColumns,
        private readonly ListBoardColumnsHandler $listColumns,
        private readonly BoardColumnPresenter $presenter,
        private readonly BoardColumnApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] ReorderBoardColumnsRequest $payload): JsonResponse
    {
        try {
            ($this->reorderColumns)(new ReorderBoardColumnsBySlugCommand($project, $payload->order ?? throw new \LogicException('order required after validation')));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }
        $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

        return new JsonResponse(new ColumnOrderResponse($this->presenter->columns($view->columns)));
    }
}
