<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\CloseInteractiveSessionCommand;
use App\Module\Bridge\Command\CloseInteractiveSessionHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Closes the open interactive runs of one session, as the exit hook of the
 * plugin asks. The firewall admits agent-scoped tokens alone. The route names
 * no project, because one session can work in several, and it carries no
 * feature flag, because a close needs no push and a session started by hand
 * needs no bridge. A body of `{"finished": true}` says the work ended well, and
 * records the init-finished milestone of every init run of the session.
 */
#[OA\Post(
    operationId: 'closeInteractiveSession',
    summary: 'Close the open interactive runs of a session',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'sessionId', description: 'The session id. A value that is not a uuid answers 422 `invalid_session_id`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'How many runs closed.', content: new OA\JsonContent(ref: '#/components/schemas/SessionClosed')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_session_id`, `invalid_finished`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new RateLimitedResponse(),
    ],
)]
#[Route(
    '/api/v1/bridge/interactive-sessions/{sessionId}/close',
    name: 'api_interactive_session_close',
    methods: ['POST'],
)]
final class CloseInteractiveSessionController extends AppController
{
    public function __construct(
        private readonly CloseInteractiveSessionHandler $closeInteractiveSession,
    ) {
    }

    public function __invoke(string $sessionId, #[MapRequestPayload] ?CloseInteractiveSessionRequest $payload = null): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Interactive session close reached without an authenticated User.');
        }

        if (!Uuid::isValid($sessionId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_session_id', 'The session id is not a valid id.');
        }

        if (null !== $payload && !$payload->isValid()) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_finished', 'The finished field is not a boolean.');
        }

        $closed = ($this->closeInteractiveSession)(new CloseInteractiveSessionCommand($user, Uuid::fromString($sessionId), $payload?->finished() ?? false));

        return new JsonResponse(['closed' => $closed]);
    }
}
