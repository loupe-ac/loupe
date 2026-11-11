<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\FindCardByNumberCommand;
use App\Module\Board\Command\FindCardByNumberHandler;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Mcp\CardPayload;
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
#[PublicPatch(
    operationId: 'updateCard',
    summary: 'Change a card',
    tags: ['Cards'],
    description: 'A field you leave out keeps its value. A call has no check against an edit of someone else, so the last write wins. The history of the card names the API key that made the change. `position`, `beforeCard` and `afterCard` place the card in its column, or in the column of `status`: pass at most one of them. `errors[].code` of a place is `place_conflict`, `card_unknown`, `neighbour_not_in_column`, `neighbour_is_card`, `position_negative` or `terminal_column`.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'number', description: 'The card number, the short label that counts from 1 inside this project.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateProjectCardRequest::class))),
    responses: [
        new SuccessResponse(200, 'The card after the change.', CardResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'card_not_found', 'route_not_found']),
        new ProblemResponse(409, ['card_managed', 'epic_children_open', 'child_design_refused']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards/{number}',
    name: 'api_card_update',
    requirements: ['number' => CardApiRoutes::NUMBER],
    methods: ['PATCH'],
)]
final class UpdateProjectCardController extends AppController
{
    public function __construct(
        private readonly FindCardByNumberHandler $findCard,
        private readonly UpdateCardHandler $updateCard,
        private readonly ShowCardHandler $showCard,
        private readonly CardPayload $payload,
        private readonly PublicCardAnswer $answer,
        private readonly CardApiFields $fields,
        private readonly CardApiProblems $problems,
        private readonly CardRefResolver $cardRefs,
        private readonly CurrentApiKeyActor $currentKey,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, int $number, #[MapRequestPayload] UpdateProjectCardRequest $payload): JsonResponse
    {
        $card = ($this->findCard)(new FindCardByNumberCommand($project, $number)) ?? throw $this->problems->cardNotFound();
        if (\count($payload->placed()) > 1) {
            throw $this->problems->invalid(['field' => 'position', 'code' => 'place_conflict', 'message' => 'Pass at most one of position, beforeCard and afterCard.']);
        }
        $resolved = $this->fields->resolve($project, $payload->status ?? null, $payload->type ?? null);

        try {
            $neighbours = $this->cardRefs->resolve($project, array_map(
                static fn (int|string $reference): array => [$reference],
                array_filter(['beforeCard' => $payload->beforeCard ?? null, 'afterCard' => $payload->afterCard ?? null], static fn (int|string|null $reference): bool => null !== $reference),
            ));
            $card = ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: $this->currentKey->actorKind(Actor::Agent),
                title: $payload->title ?? null,
                body: $payload->body ?? null,
                type: $resolved['type'],
                column: $resolved['column'],
                pullRequestUrls: $payload->pullRequestUrls ?? null,
                documentIds: $payload->documentIds ?? null,
                artifactIds: $payload->artifactIds ?? null,
                position: $payload->position ?? null,
                relatedCards: isset($payload->relatedCards) ? array_map(static fn (RelatedCardInput $input) => $input->toLink(), $payload->relatedCards) : null,
                parentCardId: $payload->parentCardId ?? null,
                laneEnabled: $payload->laneEnabled ?? null,
                beforeCardId: ($neighbours['beforeCard'][0] ?? null)?->id?->toRfc4122(),
                afterCardId: ($neighbours['afterCard'][0] ?? null)?->id?->toRfc4122(),
                requiresHumanReview: $payload->requiresHumanReview ?? null,
                values: $payload->values ?? null,
                clearParent: true === ($payload->clearParent ?? null),
                childDesign: $payload->childDesign ?? null,
                requireChildDesign: true,
                strictPlace: true,
            ))->card;
        } catch (DomainErrors|CardManaged|EpicChildrenOpen|ChildDesignRefused $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse($this->answer->card($this->payload->forCard(($this->showCard)(new ShowCardCommand($card))), (string) $project->id));
    }
}
