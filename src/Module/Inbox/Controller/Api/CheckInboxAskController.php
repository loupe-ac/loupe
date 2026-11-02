<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\CheckInboxAskCommand;
use App\Module\Inbox\Command\CheckInboxAskHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Tells the bridge, before it resumes a session, whether the ask closed and
 * whether that session already read every item. The firewall admits
 * agent-scoped tokens alone.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[OA\Get(
    operationId: 'checkInboxAsk',
    summary: 'Check an ask before a resume',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'askId', description: 'The ask id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The state of the ask.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `ask_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RateLimit('agent_inbox_ask_checks', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(InboxInstallFlags::FLAG_INBOX_ENABLED)]
#[Route(
    '/api/v1/bridge/projects/{handle}/inbox/asks/{askId}',
    name: 'api_project_inbox_ask_check',
    requirements: ['handle' => '[^/]+', 'askId' => Requirement::UUID],
    methods: ['GET'],
)]
#[Route(
    '/api/projects/{handle}/inbox/asks/{askId}',
    name: 'api_legacy_project_inbox_ask_check',
    requirements: ['handle' => '[^/]+', 'askId' => Requirement::UUID],
    methods: ['GET'],
)]
final class CheckInboxAskController extends AppController
{
    public function __construct(
        private readonly CheckInboxAskHandler $checkAsk,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(string $handle, string $askId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Inbox ask check reached without an authenticated User.');
        }

        $view = ($this->checkAsk)(new CheckInboxAskCommand($user, $handle, Uuid::fromString($askId)));

        if (null === $view->project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }
        if (null === $view->ask) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'ask_not_found', 'No ask answers this request.');
        }

        return $this->json([
            'askId' => (string) $view->ask->id,
            'closed' => null !== $view->ask->closedAt,
            'allRead' => $view->allRead,
        ]);
    }
}
