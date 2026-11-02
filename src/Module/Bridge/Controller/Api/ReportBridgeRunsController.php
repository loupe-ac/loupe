<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportBridgeRunsCommand;
use App\Module\Bridge\Command\ReportBridgeRunsHandler;
use App\Outbox\AgentPush;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Takes the list of runs one of the caller's bridges still holds, which the
 * bridge sends on each connect. The firewall admits agent-scoped tokens alone.
 */
#[OA\Put(
    operationId: 'reportBridgeRuns',
    summary: 'Report the runs a bridge still holds',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'bridgeId', description: 'The uuid the bridge generates on its first start. Any other value answers 404.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 204, description: 'The report is taken.'),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/bridges/{bridgeId}/runs',
    name: 'api_bridge_runs_report',
    requirements: ['bridgeId' => Requirement::UUID],
    methods: ['PUT'],
)]
#[Route(
    '/api/bridges/{bridgeId}/runs',
    name: 'api_legacy_bridge_runs_report',
    requirements: ['bridgeId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class ReportBridgeRunsController extends AppController
{
    public function __construct(
        private readonly ReportBridgeRunsHandler $reportBridgeRuns,
    ) {
    }

    public function __invoke(string $bridgeId, #[MapRequestPayload] ReportBridgeRunsRequest $payload): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Bridge runs endpoint reached without an authenticated User.');
        }

        ($this->reportBridgeRuns)(new ReportBridgeRunsCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            runs: $payload->states(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
