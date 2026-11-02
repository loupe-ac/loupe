<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowCardVerdictCommand;
use App\Module\Board\Command\ShowCardVerdictHandler;
use App\Module\Board\Command\ShowCardVerdictView;
use App\Module\Board\Command\VerdictActionOption;
use App\Module\Board\Command\VerdictPullRequestOption;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Service\PullRequestLabel;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the widget shows in its verdict panel for one card. The firewall grants
 * `^/api/v1/live-review-widget/` to the widget token, so this path needs no line of its own.
 */
#[OA\Get(
    operationId: 'showCardVerdict',
    summary: 'Read the verdict panel of a card',
    tags: ['Widget'],
    parameters: [
        new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'cardId', description: 'The card id.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'What the widget shows in its verdict panel.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `card_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[Route(
    '/api/v1/live-review-widget/cards/{cardId}/verdict',
    name: 'api_board_card_verdict_show',
    requirements: ['cardId' => '[^/]+'],
    methods: ['GET'],
)]
#[Route(
    '/api/board/cards/{cardId}/verdict',
    name: 'api_legacy_board_card_verdict_show',
    requirements: ['cardId' => '[^/]+'],
    methods: ['GET'],
)]
final class ShowCardVerdictController extends AppController
{
    public function __construct(
        private readonly ShowCardVerdictHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(string $cardId): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $reviewer = $this->getUser();
        if (!$reviewer instanceof User) {
            throw new \LogicException('The verdict panel was reached without an authenticated User.');
        }

        $view = ($this->handler)(new ShowCardVerdictCommand($project, $cardId, $reviewer));
        if (!$view instanceof ShowCardVerdictView) {
            return $this->json(['error' => 'card_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $latest = $view->latest;

        return $this->json([
            'cardId' => (string) $view->card->id,
            'connection' => ['state' => $view->connection, 'login' => $view->login],
            'pullRequests' => array_map(
                static fn (VerdictPullRequestOption $option): array => [
                    'id' => (string) $option->id,
                    'label' => $option->label,
                    'ownPullRequest' => $option->ownPullRequest,
                ],
                $view->pullRequests,
            ),
            'notes' => $view->notes,
            'preview' => array_map(
                static fn (array $options): array => ['actions' => array_map(
                    static fn (VerdictActionOption $option): array => ['code' => $option->code, 'label' => $option->label],
                    $options,
                )],
                $view->actions,
            ),
            'latestVerdict' => null === $latest ? null : [
                'id' => (string) $latest->id,
                'kind' => $latest->kind->value,
                'message' => $latest->message,
                'createdAt' => $latest->createdAt->format(\DATE_ATOM),
                'deliveries' => array_map(
                    static fn (CardVerdictDelivery $delivery): array => [
                        'pullRequestId' => (string) $delivery->pullRequest->id,
                        'label' => PullRequestLabel::of($delivery->pullRequest),
                        'state' => $delivery->state->value,
                        'reason' => $delivery->reason,
                        'reviewUrl' => $delivery->reviewUrl,
                    ],
                    $view->latestDeliveries,
                ),
            ],
        ]);
    }
}
