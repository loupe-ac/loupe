<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\IssueWorkerRunCredentialCommand;
use App\Module\Bridge\Command\IssueWorkerRunCredentialHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Issues the run credential of one worker launch to the bridge. The firewall
 * admits agent-scoped tokens alone, so a run token, which carries no agent
 * scope, cannot mint another one. The response carries the token once.
 *
 * The limit keys on the launch the body names, and not on the credential,
 * because one bridge credential issues for every launch of its pool.
 */
#[OA\Post(
    operationId: 'issueWorkerRunCredential',
    summary: 'Issue the credential of a worker launch',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'runId', description: 'The key the bridge gave the run. A value that is not a uuid answers 422 `invalid_run_id`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 201, description: 'The token.', content: new OA\JsonContent(ref: '#/components/schemas/IssuedToken')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_run_id`, `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new RateLimitedResponse(),
    ],
)]
#[Route(
    '/api/v1/bridge/projects/{handle}/worker-runs/{runId}/credential',
    name: 'api_project_worker_run_credential_issue',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['POST'],
)]
final class IssueWorkerRunCredentialController extends AppController
{
    public function __construct(
        private readonly IssueWorkerRunCredentialHandler $issueWorkerRunCredential,

        #[Autowire(service: 'limiter.agent_worker_run_credentials')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(string $handle, string $runId, #[MapRequestPayload] IssueWorkerRunCredentialRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Worker run credential endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($runId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_run_id', 'The run key is not a valid id.');
        }

        $run = Uuid::fromString($runId);
        $limit = $this->limiter
            ->create(\sprintf('%s:%s:%s', $run->toRfc4122(), $payload->bridgeId()->toRfc4122(), $payload->sessionId()->toRfc4122()))
            ->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many credential requests for this launch.');
        }

        $issued = ($this->issueWorkerRunCredential)(new IssueWorkerRunCredentialCommand($user, $handle, $payload->bridgeId(), $run, $payload->sessionId()));
        if (null === $issued) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this handle.');
        }

        return $this->json([
            'token' => $issued->token,
            'expiresAt' => $issued->expiresAt->format(\DATE_ATOM),
        ], JsonResponse::HTTP_CREATED);
    }
}
