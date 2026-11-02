<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowProjectCardCommand;
use App\Module\Board\Command\ShowProjectCardHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The column a card is in now, and whether a person paused the agents on it.
 * The bridge reads it before it resumes a run, and skips the resume when the
 * card left that column. The firewall admits agent-scoped tokens alone.
 */
#[OA\Get(
    operationId: 'getBridgeProjectCard',
    summary: 'Read the column of a card before a resume',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'cardId', description: 'The card id.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The card.', content: new OA\JsonContent(ref: '#/components/schemas/ProjectCard')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `card_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[Route(
    '/api/v1/bridge/projects/{handle}/cards/{cardId}',
    name: 'api_project_board_card_show',
    requirements: ['handle' => '[^/]+', 'cardId' => '[^/]+'],
    methods: ['GET'],
)]
#[Route(
    '/api/projects/{handle}/board/cards/{cardId}',
    name: 'api_legacy_project_board_card_show',
    requirements: ['handle' => '[^/]+', 'cardId' => '[^/]+'],
    methods: ['GET'],
)]
final class ShowProjectCardController extends AppController
{
    public function __construct(
        private readonly ShowProjectCardHandler $showCard,
    ) {
    }

    public function __invoke(string $handle, string $cardId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Card endpoint reached without an authenticated User.');
        }

        $view = ($this->showCard)(new ShowProjectCardCommand($user, $handle, $cardId));
        if (null === $view->project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        if (null === $view->card) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'card_not_found', 'No card answers this request.');
        }

        return $this->json([
            'cardId' => (string) $view->card->id,
            'number' => $view->card->number,
            'column' => $view->card->column->slug,
            'held' => $view->held,
        ]);
    }
}
