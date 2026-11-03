<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\RevokeApiKeyCommand;
use App\Module\OAuth\Command\RevokeApiKeyHandler;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Security\ApiKeyVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('revoke-api-key')]
#[IsGranted(ApiKeyVoter::MANAGE, subject: 'apiKey')]
#[Route(
    '/account/api-keys/{id:apiKey}/revoke',
    name: 'app_account_api_key_revoke',
    methods: ['POST'],
)]
final class RevokeApiKeyController extends AppController
{
    public function __construct(
        private readonly RevokeApiKeyHandler $revokeApiKey,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ApiKey $apiKey): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        try {
            ($this->revokeApiKey)(new RevokeApiKeyCommand($apiKey, $user));
            $this->addFlash('success', $this->translator->trans('oauth.api_keys.flash.revoked'));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans($e->errors['key'] ?? 'oauth.api_key.error.already_revoked'));
        }

        return $this->redirectToRoute('app_account_api_keys');
    }
}
