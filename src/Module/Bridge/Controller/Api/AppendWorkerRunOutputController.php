<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\AppendWorkerRunOutputCommand;
use App\Module\Bridge\Command\AppendWorkerRunOutputHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Appends text to the live output log of one worker run. The run id in the
 * path is the key the bridge gave the run. A run of another bridge counts as
 * no run. The firewall admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/projects/{handle}/worker-runs/{runId}/output',
    name: 'api_project_worker_run_output_report',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['PUT'],
)]
#[Route(
    '/api/projects/{handle}/worker-runs/{runId}/output',
    name: 'api_legacy_project_worker_run_output_report',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['PUT'],
)]
final class AppendWorkerRunOutputController extends AppController
{
    public function __construct(
        private readonly AppendWorkerRunOutputHandler $appendOutput,
    ) {
    }

    public function __invoke(string $handle, string $runId, #[MapRequestPayload] AppendWorkerRunOutputRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Output endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($runId)) {
            return $this->json(['error' => 'invalid_run_id'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ($this->appendOutput)(new AppendWorkerRunOutputCommand(
            owner: $user,
            handle: $handle,
            runKey: Uuid::fromString($runId),
            bridgeId: Uuid::fromString($payload->bridgeId ?? throw new \LogicException('bridgeId required after validation')),
            offset: $payload->offset ?? throw new \LogicException('offset required after validation'),
            text: $payload->text ?? throw new \LogicException('text required after validation'),
        ));

        if (!$result->projectFound) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$result->runFound) {
            return $this->json(['error' => 'run_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['length' => $result->length, 'capped' => $result->capped]);
    }
}
