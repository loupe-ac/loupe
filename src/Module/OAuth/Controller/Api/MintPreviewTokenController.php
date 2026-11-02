<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\MintPreviewTokenCommand;
use App\Module\OAuth\Command\MintPreviewTokenHandler;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mints a preview token for one of the caller's projects. The firewall admits
 * agent-scoped tokens alone. The response carries the token once, and it is
 * the only time anything shows it.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[RateLimit('agent_preview_tokens', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/v1/bridge/projects/{handle}/preview-tokens',
    name: 'api_project_preview_token_mint',
    requirements: ['handle' => '[^/]+'],
    methods: ['POST'],
)]
#[Route(
    '/api/projects/{handle}/preview-tokens',
    name: 'api_legacy_project_preview_token_mint',
    requirements: ['handle' => '[^/]+'],
    methods: ['POST'],
)]
final class MintPreviewTokenController extends AppController
{
    public function __construct(
        private readonly MintPreviewTokenHandler $mintPreviewToken,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(string $handle, #[MapRequestPayload] MintPreviewTokenRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Preview token mint reached without an authenticated User.');
        }

        try {
            $view = ($this->mintPreviewToken)(new MintPreviewTokenCommand($user, $handle, $payload->label ?? '', $payload->cardId()));
        } catch (DomainErrors $e) {
            throw match (true) {
                isset($e->errors['handle']) => ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this handle.'),
                'oauth.preview_token.error.card_finished' === ($e->errors['card'] ?? null) => ApiProblemException::refused(JsonResponse::HTTP_CONFLICT, 'card_finished', 'The card is finished.'),
                isset($e->errors['card']) => ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'card_not_found', 'No card answers this request.'),
                default => ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_label', 'The label is not valid.'),
            };
        }

        return $this->json([
            'token' => $view->token,
            'expiresAt' => $view->expiresAt->format(\DATE_ATOM),
        ], JsonResponse::HTTP_CREATED);
    }
}
