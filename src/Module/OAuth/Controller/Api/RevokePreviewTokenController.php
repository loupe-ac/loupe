<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\RevokePreviewTokensCommand;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Revokes the caller's live preview tokens of one label on a project. A label
 * with no live token is not an error, so a teardown script can run twice.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[OA\Delete(
    operationId: 'revokePreviewTokens',
    summary: 'Revoke the live preview tokens of a label',
    tags: ['Tokens'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'label', description: 'The label.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'How many tokens went.', content: new OA\JsonContent(ref: '#/components/schemas/PreviewTokenRevoked')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RateLimit('agent_preview_tokens', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/v1/bridge/projects/{handle}/preview-tokens/{label}',
    name: 'api_project_preview_token_revoke',
    requirements: ['handle' => '[^/]+', 'label' => '[^/]+'],
    methods: ['DELETE'],
)]
#[Route(
    '/api/projects/{handle}/preview-tokens/{label}',
    name: 'api_legacy_project_preview_token_revoke',
    requirements: ['handle' => '[^/]+', 'label' => '[^/]+'],
    methods: ['DELETE'],
)]
final class RevokePreviewTokenController extends AppController
{
    public function __construct(
        private readonly RevokePreviewTokensHandler $revokePreviewTokens,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(string $handle, string $label): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Preview token revoke reached without an authenticated User.');
        }

        try {
            $revoked = ($this->revokePreviewTokens)(new RevokePreviewTokensCommand(owner: $user, handle: $handle, label: $label));
        } catch (DomainErrors) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        return $this->json(['revoked' => $revoked]);
    }
}
