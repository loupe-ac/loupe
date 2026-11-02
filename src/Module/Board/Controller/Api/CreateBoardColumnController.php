<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\AddBoardColumnCommand;
use App\Module\Board\Command\AddBoardColumnHandler;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(BoardColumnVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'createBoardColumn',
    summary: 'Add a column to the board',
    tags: ['Columns'],
    description: '`Location` names the new column by its slug. No route reads one column, so read it from the list.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateBoardColumnRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new column, and every column of the board.', ColumnWriteResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['column_slug_taken', 'idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/board/columns',
    name: 'api_board_column_create',
    methods: ['POST'],
)]
final class CreateBoardColumnController extends AppController
{
    public function __construct(
        private readonly AddBoardColumnHandler $addColumn,
        private readonly ListBoardColumnsHandler $listColumns,
        private readonly BoardColumnPresenter $presenter,
        private readonly BoardColumnApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] CreateBoardColumnRequest $payload): JsonResponse
    {
        try {
            $column = ($this->addColumn)(new AddBoardColumnCommand($project, $payload->label ?? throw new \LogicException('label required after validation')));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }
        $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

        return new JsonResponse(
            new ColumnWriteResponse($column->slug, $this->presenter->columns($view->columns)),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_board_column_show', ['project' => (string) $project->id, 'column' => $column->slug], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
