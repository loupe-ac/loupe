<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\ReorderCardsCommand;
use App\Module\Board\Command\ReorderCardsHandler;
use App\Module\Board\Command\ReorderedCard;
use App\Module\Board\Command\ReorderPlacement;
use App\Module\Board\Service\CardApiFields;
use App\Module\Board\Service\CardApiProblems;
use App\Module\Board\Service\CardRefResolver;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Workflow\Contract\Actor;
use App\Security\CurrentApiKeyActor;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'reorderCards',
    summary: 'Place a block of cards in one column',
    tags: ['Cards'],
    description: 'The listed cards form one block, in list order, at the place. The other cards keep their order around it. The call changes ranks only: every listed card must already sit in the column, and a terminal column refuses the call. The whole call is refused or applied: nothing changes on a 422. A rank change starts no workflow work and writes no history row. `errors[].code` is `place_missing`, `place_conflict`, `unknown_column`, `terminal_column`, `list_empty`, `card_listed_twice`, `card_unknown`, `card_not_in_column`, `neighbour_in_list` or `neighbour_not_in_column`.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ReorderProjectCardsRequest::class))),
    responses: [
        new SuccessResponse(200, 'The placed block and the cards just above and below it.', ReorderProjectCardsResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards/reorder',
    name: 'api_card_reorder',
    methods: ['POST'],
)]
final class ReorderProjectCardsController extends AppController
{
    public function __construct(
        private readonly ReorderCardsHandler $reorderCards,
        private readonly CardRefResolver $cardRefs,
        private readonly CardApiFields $fields,
        private readonly CardApiProblems $problems,
        private readonly CurrentApiKeyActor $currentKey,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] ReorderProjectCardsRequest $payload): JsonResponse
    {
        $placed = $payload->placed();
        if ([] === $placed) {
            throw $this->problems->invalid(['field' => 'at', 'code' => 'place_missing', 'message' => 'Pass one of at, beforeCard and afterCard to say where the block goes.']);
        }
        if (\count($placed) > 1) {
            throw $this->problems->invalid(['field' => 'at', 'code' => 'place_conflict', 'message' => 'Pass only one of at, beforeCard and afterCard.']);
        }
        $column = $this->fields->column($project, $payload->column ?? '', 'column');

        try {
            $groups = ['cards' => array_values($payload->cards ?? [])];
            foreach (['beforeCard' => $payload->beforeCard ?? null, 'afterCard' => $payload->afterCard ?? null] as $field => $reference) {
                if (null !== $reference) {
                    $groups[$field] = [$reference];
                }
            }
            $resolved = $this->cardRefs->resolve($project, $groups);
            $neighbour = $resolved['beforeCard'][0] ?? $resolved['afterCard'][0] ?? null;

            $view = ($this->reorderCards)(new ReorderCardsCommand(
                project: $project,
                column: $column,
                cardIds: array_map(static fn ($card): string => $card->id?->toRfc4122() ?? throw new \LogicException('A stored card has an id.'), $resolved['cards']),
                placement: match (true) {
                    isset($payload->beforeCard) => ReorderPlacement::Before,
                    isset($payload->afterCard) => ReorderPlacement::After,
                    'top' === $payload->at => ReorderPlacement::Top,
                    default => ReorderPlacement::Bottom,
                },
                neighbourId: $neighbour?->id?->toRfc4122(),
                actor: $this->currentKey->actorKind(Actor::Agent),
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        $row = static fn (?ReorderedCard $card): ?PlacedCardResponse => null === $card ? null : new PlacedCardResponse($card->number, $card->title, $card->rank);

        return new JsonResponse(new ReorderProjectCardsResponse(
            $row($view->above),
            array_map(static fn (ReorderedCard $card): PlacedCardResponse => new PlacedCardResponse($card->number, $card->title, $card->rank), $view->cards),
            $row($view->below),
        ));
    }
}
