<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Board\Command\ShowWidgetCardStartCommand;
use App\Module\Board\Command\ShowWidgetCardStartHandler;
use App\Module\Board\Command\WidgetCardNotFound;
use App\Module\Board\Service\WidgetCardStartAnswer;
use App\Module\Board\Service\WidgetSignIn;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Tells the widget where a card it raised stands and which columns it may move the card to. */
#[OA\Get(
    operationId: 'showWidgetCardStart',
    summary: 'Show where a widget card may start',
    tags: ['Widget'],
    parameters: [
        new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'cardId', description: 'The card id. A value that is not a uuid answers 404 `not_found`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The card and its moves.', content: new OA\JsonContent(ref: '#/components/schemas/WidgetCardStart')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`, `start_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new RateLimitedResponse(),
    ],
)]
#[Route(
    '/api/v1/live-review-widget/cards/{cardId}/start',
    name: 'api_board_widget_card_start_show',
    methods: ['GET'],
)]
final class ShowWidgetCardStartController extends AppController
{
    public function __construct(
        private readonly ShowWidgetCardStartHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly WidgetSignIn $signIn,
        private readonly WidgetCardStartAnswer $answer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(string $cardId): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'token_not_bound_to_site', 'The token is not bound to a site.');
        }
        if ($this->signIn->isPreviewToken()) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'start_not_allowed', 'A preview token starts no card.');
        }

        try {
            $view = ($this->handler)(new ShowWidgetCardStartCommand($project, $cardId));
        } catch (WidgetCardNotFound) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'not_found', 'No such record exists for this token.');
        }

        return $this->json([
            'number' => $view->card->number,
            'url' => $this->urls->generate(
                'app_board_card',
                ['projectId' => (string) $project->id, 'cardId' => (string) $view->card->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'column' => $this->answer->columnName($view),
            'startsWork' => $this->answer->startsWork($view),
            'moves' => $this->answer->moves($project, $view->moves),
        ]);
    }
}
