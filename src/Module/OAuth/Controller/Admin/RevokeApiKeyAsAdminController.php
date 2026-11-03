<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\RevokeApiKeyCommand;
use App\Module\OAuth\Command\RevokeApiKeyHandler;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Security\ApiKeyVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-revoke-api-key')]
#[IsGranted(ApiKeyVoter::REVOKE_AS_ADMIN, subject: 'apiKey')]
#[Route(
    '/admin/api-keys/{id:apiKey}/revoke',
    name: 'app_admin_api_key_revoke',
    methods: ['POST'],
)]
final class RevokeApiKeyAsAdminController extends AppController
{
    public function __construct(
        private readonly RevokeApiKeyHandler $revokeApiKey,
        private readonly AdminReturnTo $returnTo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ApiKey $apiKey, Request $request): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s).', self::class, get_debug_type($actor)));
        }

        try {
            ($this->revokeApiKey)(new RevokeApiKeyCommand($apiKey, $actor));
            $this->addFlash('success', $this->translator->trans('oauth.api_keys.flash.revoked'));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans($e->errors['key'] ?? 'oauth.api_key.error.already_revoked'));
        }

        return $this->redirect(
            $this->returnTo->validate('user', $request->request->get('returnTo'))
                ?? $this->generateUrl('app_admin_users_detail', ['id' => (string) $apiKey->owner->id]),
        );
    }
}
