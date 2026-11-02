<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ResolveExperimentPinCommand;
use App\Module\Bridge\Command\ResolveExperimentPinHandler;
use App\Outbox\AgentPush;
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
