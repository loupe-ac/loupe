<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ShowInteractiveSessionCardRunCommand;
use App\Module\Bridge\Command\ShowInteractiveSessionCardRunHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the session start hook of the plugin which workflow template a project
 * follows and whether the session already holds an open run on a card. The
 * firewall admits agent-scoped tokens alone. The project comes from the
 * X-Loupe-Project header and never from a fallback, because the hook must not
 * guess a project. A project the token owner does not own answers like a
 * missing one. It shares the agent_worker_runs rate limit, which
 * RateLimitWorkerRunReports applies by route name.
 */
#[OA\Get(
    operationId: 'showInteractiveSessionCardRun',
    summary: 'Read the workflow template of a project and the open card run of a session',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'sessionId', description: 'The session id. A value that is not a uuid answers 422 `invalid_session_id`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'X-Loupe-Project', description: 'A project id or a project slug. A missing or blank value answers 422 `project_header_required`.', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The template and the card run state.', content: new OA\JsonContent(ref: '#/components/schemas/SessionCardRun')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `project_header_required`, `invalid_session_id`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new RateLimitedResponse(),
    ],
)]
#[Route(
    '/api/v1/bridge/interactive-sessions/{sessionId}/card-run',
    name: 'api_interactive_session_card_run',
    methods: ['GET'],
)]
final class ShowInteractiveSessionCardRunController extends AppController
{
    public function __construct(
        private readonly ShowInteractiveSessionCardRunHandler $showCardRun,
    ) {
    }

    public function __invoke(Request $request, string $sessionId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Interactive session card run reached without an authenticated User.');
        }

        $handle = trim((string) $request->headers->get(AuthenticatedProjectResolver::PROJECT_HEADER, ''));
        if ('' === $handle) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'project_header_required', 'This route needs the X-Loupe-Project header.');
        }

        if (!Uuid::isValid($sessionId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_session_id', 'The session id is not a valid id.');
        }

        $view = ($this->showCardRun)(new ShowInteractiveSessionCardRunCommand($user, $handle, Uuid::fromString($sessionId)));
        if (null === $view) {
            throw ApiProblemException::projectNotFound();
        }

        return new JsonResponse(['template' => $view->templateKey, 'openCardRun' => $view->openCardRun]);
    }
}
