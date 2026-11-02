<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\ConfigureBoardColumnCommand;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
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
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The call has no dialog that showed the column, so the expected values are the
 * column as this call reads it. A change that lands between the read and the
 * write answers `columns_changed`.
 */
#[IsGranted(BoardColumnVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'updateBoardColumn',
    summary: 'Rename a column, or set whether it is terminal',
    tags: ['Columns'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'column', description: 'The slug of a column, from the column list.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ColumnUpdate')),
    responses: [
        new SuccessResponse(200, 'The column after the change, and every column of the board.', ColumnWriteResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'column_not_found', 'route_not_found']),
        new ProblemResponse(409, ['column_slug_taken', 'no_terminal_column', 'backlog_locked', 'columns_changed']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/board/columns/{column}',
    name: 'api_board_column_update',
    methods: ['PATCH'],
)]
final class UpdateBoardColumnController extends AppController
{
    public function __construct(
        private readonly FindBoardColumnBySlugHandler $findColumn,
        private readonly ConfigureBoardColumnHandler $configureColumn,
        private readonly ListBoardColumnsHandler $listColumns,
        private readonly BoardColumnPresenter $presenter,
        private readonly BoardColumnApiProblems $problems,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $column, #[MapRequestPayload] UpdateBoardColumnRequest $payload): JsonResponse
    {
        $found = ($this->findColumn)(new FindBoardColumnBySlugCommand($project, $column)) ?? throw $this->problems->columnNotFound();

        try {
            // The handler keeps a seeded label's key only when it gets the translated label back.
            ($this->configureColumn)(new ConfigureBoardColumnCommand(
                column: $found,
                actor: Actor::Human,
                label: $payload->label ?? $this->translator->trans($found->label),
                terminal: $payload->terminal ?? $found->terminal,
                expectedLabel: $found->label,
                expectedTerminal: $found->terminal,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }
        $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

        return new JsonResponse(new ColumnWriteResponse($found->slug, $this->presenter->columns($view->columns)));
    }
}
