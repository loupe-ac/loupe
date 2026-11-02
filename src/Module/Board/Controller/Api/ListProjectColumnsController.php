<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\ListProjectColumnsCommand;
use App\Module\Board\Command\ListProjectColumnsHandler;
use App\Module\Board\Entity\BoardColumn;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of one of the caller's boards, which the bridge CLI checks its
 * rule file against at start. The firewall admits agent-scoped tokens alone.
 */
#[OA\Get(
    operationId: 'listBridgeProjectColumns',
    summary: 'List the columns the CLI checks its rule file against',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The project and its columns.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[Route(
    '/api/v1/bridge/projects/{handle}/columns',
    name: 'api_project_board_column_list',
    requirements: ['handle' => '[^/]+'],
    methods: ['GET'],
)]
#[Route(
    '/api/projects/{handle}/board/columns',
    name: 'api_legacy_project_board_column_list',
    requirements: ['handle' => '[^/]+'],
    methods: ['GET'],
)]
final class ListProjectColumnsController extends AppController
{
    public function __construct(
        private readonly ListProjectColumnsHandler $listColumns,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(string $handle): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Columns endpoint reached without an authenticated User.');
        }

        $view = ($this->listColumns)(new ListProjectColumnsCommand($user, $handle));
        if (null === $view->project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        return $this->json([
            'project' => ['id' => (string) $view->project->id, 'slug' => $view->project->slug],
            'columns' => array_map(
                fn (BoardColumn $column): array => [
                    'slug' => $column->slug,
                    // A seeded label is a translation key, and a typed one matches no key.
                    'label' => $this->translator->trans($column->label),
                    'terminal' => $column->terminal,
                    'default' => $column->backlog,
                    'backlog' => $column->backlog,
                ],
                $view->columns,
            ),
        ]);
    }
}
