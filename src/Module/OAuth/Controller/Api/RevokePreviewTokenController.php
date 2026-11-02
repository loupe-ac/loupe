<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller\Api;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\RevokePreviewTokensCommand;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use App\Security\CredentialRateLimitKey;
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
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['revoked' => $revoked]);
    }
}
