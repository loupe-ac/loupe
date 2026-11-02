<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ResolveExperimentPinCommand;
use App\Module\Bridge\Command\ResolveExperimentPinHandler;
use App\Outbox\AgentPush;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Answers the variant of one experiment that one card runs with. The firewall
 * admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code. The route accepts any
 * segment for that reason, and the controller checks the names itself.
 */
#[OA\Put(
    operationId: 'resolveExperimentPin',
    summary: 'Answer the variant a card runs with',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'experiment', description: 'The experiment name. A bad name answers 422 `invalid_experiment`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'cardId', description: 'The card id.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The server resolved the variant of the card.', content: new OA\JsonContent(ref: '#/components/schemas/ExperimentPinAnswer')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_card_id`, `invalid_experiment`, `invalid_variants`, `invalid_baseline`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/projects/{handle}/experiments/{experiment}/pins/{cardId}',
    name: 'api_project_experiment_pin_resolve',
    requirements: ['handle' => '[^/]+', 'experiment' => '[^/]+', 'cardId' => '[^/]+'],
    methods: ['PUT'],
)]
#[Route(
    '/api/projects/{handle}/experiments/{experiment}/pins/{cardId}',
    name: 'api_legacy_project_experiment_pin_resolve',
    requirements: ['handle' => '[^/]+', 'experiment' => '[^/]+', 'cardId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ResolveExperimentPinController extends AppController
{
    public function __construct(
        private readonly ResolveExperimentPinHandler $resolveExperimentPin,
    ) {
    }

    /** An empty body gives a null payload, which the controller refuses with its own code. */
    public function __invoke(
        string $handle,
        string $experiment,
        string $cardId,
        #[MapRequestPayload] ?ResolveExperimentPinRequest $payload = null,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Experiment pin endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($cardId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_card_id', 'The card id is not a valid id.');
        }

        if (!ResolveExperimentPinRequest::isName($experiment)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_experiment', 'The experiment is not valid.');
        }

        $choice = $payload?->choice();
        if (null === $choice) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_variants', 'The variants are not valid.');
        }

        $baseline = $payload?->baseline();
        if (null === $baseline) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_baseline', 'The baseline is not valid.');
        }

        $result = ($this->resolveExperimentPin)(new ResolveExperimentPinCommand(
            owner: $user,
            handle: $handle,
            cardId: Uuid::fromString($cardId),
            experiment: $experiment,
            candidate: $choice['candidate'],
            variants: $choice['variants'],
            baseline: $baseline,
            weights: $payload?->weights(),
            metrics: $payload?->metrics(),
            headline: $payload?->headline(),
            covers: $payload?->covers(),
        ));

        if (null === $result->variant) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        return $this->json(['variant' => $result->variant, 'switchedFrom' => $result->switchedFrom]);
    }
}
