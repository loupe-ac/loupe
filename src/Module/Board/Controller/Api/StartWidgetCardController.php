<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Board\Command\StartWidgetCardCommand;
use App\Module\Board\Command\StartWidgetCardHandler;
use App\Module\Board\Command\WidgetCardNotFound;
use App\Module\Board\Service\WidgetCardStartAnswer;
use App\Module\Board\Service\WidgetSignIn;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Moves a widget card to a column the move guard offers the reviewer. The
 * column must be in the list the card has at the write, and the card can
 * never leave for the Backlog or a terminal column.
 */
#[OA\Post(
    operationId: 'startWidgetCard',
    summary: 'Move a widget card to a column',
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['columnId'], properties: [new OA\Property(property: 'columnId', type: 'string', format: 'uuid')])),
    tags: ['Widget'],
    parameters: [
        new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'cardId', description: 'The card id. A value that is not a uuid answers 404 `not_found`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The card moved.', content: new OA\JsonContent(ref: '#/components/schemas/WidgetCardStarted')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`, `start_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 409, description: 'Refused: `start_refused`. The problem carries the `column` the card is in and the `moves` it has now.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/WidgetCardStartRefused'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new RateLimitedResponse(),
    ],
)]
#[Route(
    '/api/v1/live-review-widget/cards/{cardId}/start',
    name: 'api_board_widget_card_start',
    methods: ['POST'],
)]
final class StartWidgetCardController extends AppController
{
    public function __construct(
        private readonly StartWidgetCardHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly WidgetSignIn $signIn,
        private readonly WidgetCardStartAnswer $answer,
    ) {
    }

    public function __invoke(string $cardId, #[MapRequestPayload] StartWidgetCardRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'token_not_bound_to_site', 'The token is not bound to a site.');
        }
        if ($this->signIn->isPreviewToken()) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'start_not_allowed', 'A preview token starts no card.');
        }

        try {
            $result = ($this->handler)(new StartWidgetCardCommand($project, $cardId, $payload->columnId ?? throw new \LogicException('columnId required after validation')));
        } catch (WidgetCardNotFound) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'not_found', 'No such record exists for this token.');
        }

        $column = $this->answer->columnName($result->view);
        if (!$result->moved) {
            throw ApiProblemException::refused(JsonResponse::HTTP_CONFLICT, 'start_refused', 'The card cannot move to that column now.', ['column' => $column, 'moves' => $this->answer->moves($project, $result->view->moves)]);
        }

        return $this->json(['column' => $column]);
    }
}
