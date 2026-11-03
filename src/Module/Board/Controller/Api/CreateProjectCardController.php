<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Service\CardApiFields;
use App\Module\Board\Service\CardApiProblems;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'createCard',
    summary: 'Add a card to the board',
    tags: ['Cards'],
    description: 'With no `status`, the card lands in Backlog. `Location` names the new card. The history of the card names the API key that made it.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateProjectCardRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new card.', CardResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['card_managed', 'child_design_refused', 'idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards',
    name: 'api_card_create',
    methods: ['POST'],
)]
final class CreateProjectCardController extends AppController
{
    public function __construct(
        private readonly CreateCardHandler $createCard,
        private readonly ShowCardHandler $showCard,
        private readonly CardPayload $payload,
        private readonly PublicCardAnswer $answer,
        private readonly CardApiFields $fields,
        private readonly CardApiProblems $problems,
        private readonly CurrentApiKeyActor $currentKey,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] CreateProjectCardRequest $payload): JsonResponse
    {
        $actor = $this->currentKey->actorKind(Actor::Agent);
        $reporter = $this->fields->reporter($actor, $payload->reporter ?? null);
        $resolved = $this->fields->resolve($project, $payload->status ?? null, $payload->type);

        try {
            $card = ($this->createCard)(new CreateCardCommand(
                project: $project,
                title: $payload->title ?? throw new \LogicException('title required after validation'),
                body: $payload->body ?? throw new \LogicException('body required after validation'),
                type: $resolved['type'] ?? throw new \LogicException('type required after validation'),
                column: $resolved['column'],
                reporter: $reporter,
                pullRequestUrls: $payload->pullRequestUrls ?? [],
                documentIds: $payload->documentIds ?? [],
                artifactIds: $payload->artifactIds ?? [],
                relatedCards: array_map(static fn (RelatedCardInput $input) => $input->toLink(), $payload->relatedCards ?? []),
                parentCardId: $payload->parentCardId ?? null,
                laneEnabled: $payload->laneEnabled ?? null,
                actor: $actor,
                requiresHumanReview: $payload->requiresHumanReview ?? null,
                childDesign: $payload->childDesign ?? null,
                requireChildDesign: true,
            ));
        } catch (DomainErrors|CardManaged|ChildDesignRefused $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(
            $this->answer->card($this->payload->forCard(($this->showCard)(new ShowCardCommand($card))), (string) $project->id),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_card_show', ['project' => (string) $project->id, 'number' => $card->number], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
