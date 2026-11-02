<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\OAuth\Command\RemoveApiKeyCommand;
use App\Module\OAuth\Command\RemoveApiKeyHandler;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Security\ApiKeyVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('remove-api-key')]
#[IsGranted(ApiKeyVoter::MANAGE, subject: 'apiKey')]
#[Route(
    '/account/api-keys/{id:apiKey}/remove',
    name: 'app_account_api_key_remove',
    methods: ['POST'],
)]
final class RemoveApiKeyController extends AppController
{
    public function __construct(
        private readonly RemoveApiKeyHandler $removeApiKey,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ApiKey $apiKey): Response
    {
        try {
            ($this->removeApiKey)(new RemoveApiKeyCommand($apiKey));
            $this->addFlash('success', $this->translator->trans('oauth.api_keys.flash.removed'));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans($e->errors['key'] ?? 'oauth.api_key.error.remove_active'));
        }

        return $this->redirectToRoute('app_account_api_keys');
    }
}
