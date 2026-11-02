<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\SendCardVerdictCommand;
use App\Module\Board\Command\SendCardVerdictHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Records a verdict from the site-review widget. The firewall grants
 * `^/api/v1/live-review-widget/` to the widget token, so this path needs no line of its own.
 */
#[OA\Post(operationId: 'sendCardVerdict', summary: 'Record a verdict from the widget', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', additionalProperties: true)), tags: ['Widget'], parameters: [
    new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'cardId', description: 'The card id.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
], responses: [
    new OA\Response(response: 201, description: 'The verdict is recorded.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
    new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 404, description: 'Refused: `card_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 409, description: 'Refused: `card_closed`, `submission_reused`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 422, description: 'Refused: `message_required`, `pull_request_not_on_card`, `verdict_refused`, `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
])]
#[Route(
    '/api/v1/live-review-widget/cards/{cardId}/verdicts',
    name: 'api_board_card_verdict_send',
    requirements: ['cardId' => '[^/]+'],
    methods: ['POST'],
)]
#[Route(
    '/api/board/cards/{cardId}/verdicts',
    name: 'api_legacy_board_card_verdict_send',
    requirements: ['cardId' => '[^/]+'],
    methods: ['POST'],
)]
final class SendCardVerdictController extends AppController
{
    private const array REFUSALS = [
        SendCardVerdictHandler::CARD_GONE => ['card_not_found', JsonResponse::HTTP_NOT_FOUND],
        SendCardVerdictHandler::CARD_CLOSED => ['card_closed', JsonResponse::HTTP_CONFLICT],
        SendCardVerdictHandler::MESSAGE_REQUIRED => ['message_required', JsonResponse::HTTP_UNPROCESSABLE_ENTITY],
        SendCardVerdictHandler::PULL_REQUEST_NOT_ON_CARD => ['pull_request_not_on_card', JsonResponse::HTTP_UNPROCESSABLE_ENTITY],
        SendCardVerdictHandler::SUBMISSION_REUSED => ['submission_reused', JsonResponse::HTTP_CONFLICT],
    ];

    public function __construct(
        private readonly SendCardVerdictHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(string $cardId, #[MapRequestPayload] SendCardVerdictRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'token_not_bound_to_site', 'The token is not bound to a site.');
        }

        $reviewer = $this->getUser();
        if (!$reviewer instanceof User) {
            throw new \LogicException('A verdict was sent without an authenticated User.');
        }

        try {
            $verdict = ($this->handler)(new SendCardVerdictCommand(
                project: $project,
                cardId: $cardId,
                reviewer: $reviewer,
                kind: $payload->kind ?? throw new \LogicException('kind required after validation'),
                pullRequestIds: $payload->pullRequestIds,
                message: $payload->message,
                submissionId: null === $payload->submissionId ? null : Uuid::fromString($payload->submissionId),
            ));
        } catch (DomainErrors $error) {
            [$code, $status] = self::REFUSALS[array_first($error->errors)] ?? ['verdict_refused', JsonResponse::HTTP_UNPROCESSABLE_ENTITY];

            throw ApiProblemException::refused($status, $code, 'The verdict was refused.');
        }

        return $this->json([
            'verdictId' => (string) $verdict->id,
            'kind' => $verdict->kind->value,
            'noteCount' => \count($verdict->notes),
        ], JsonResponse::HTTP_CREATED);
    }
}
