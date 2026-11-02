<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportSessionUsageCommand;
use App\Module\Bridge\Command\ReportSessionUsageHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records the usage of every worker process of one claude session against one
 * of the caller's projects, or of one ended interactive run of the session.
 * The firewall admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/projects/{handle}/worker-runs/sessions/{sessionId}/usage',
    name: 'api_project_worker_run_session_usage_report',
    requirements: ['handle' => '[^/]+', 'sessionId' => '[^/]+'],
    methods: ['PUT'],
)]
#[Route(
    '/api/projects/{handle}/worker-runs/sessions/{sessionId}/usage',
    name: 'api_legacy_project_worker_run_session_usage_report',
    requirements: ['handle' => '[^/]+', 'sessionId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportSessionUsageController extends AppController
{
    public function __construct(
        private readonly ReportSessionUsageHandler $reportSessionUsage,
    ) {
    }

    public function __invoke(string $handle, string $sessionId, #[MapRequestPayload] ReportSessionUsageRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Session usage endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($sessionId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_session_id', 'The session id is not a valid id.');
        }

        if (null !== $payload->runId && !Uuid::isValid($payload->runId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_run_id', 'The run id is not a valid id.');
        }

        $result = ($this->reportSessionUsage)(new ReportSessionUsageCommand(
            owner: $user,
            handle: $handle,
            sessionId: Uuid::fromString($sessionId),
            processes: $payload->processes(),
            runId: null === $payload->runId ? null : Uuid::fromString($payload->runId),
        ));

        if (null === $result->runs) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        if ($result->runs !== $result->processes) {
            throw ApiProblemException::refused(JsonResponse::HTTP_CONFLICT, 'process_count_mismatch', 'The process count does not match the run.');
        }

        if ($result->ambiguousOrder) {
            throw ApiProblemException::refused(JsonResponse::HTTP_CONFLICT, 'ambiguous_start_order', 'The start order of the processes is ambiguous.');
        }

        return $this->json(['runs' => $result->runs, 'updated' => $result->updated]);
    }
}
