<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\ListApiKeysCommand;
use App\Module\OAuth\Command\ListApiKeysHandler;
use App\Module\OAuth\Form\CreateApiKeyFormType;
use App\Module\OAuth\Form\CreateApiKeyRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/api-keys',
    name: 'app_account_api_keys',
    methods: ['GET'],
)]
#[Route(
    '/account/api-keys/create',
    name: 'app_account_api_keys_create_dialog',
    defaults: ['openCreateDialog' => true],
    methods: ['GET'],
)]
final class ListApiKeysController extends AppController
{
    public const string CREATE_FORM = 'createForm';

    /** Flash type that carries the new key from the create response to the page that shows it once. */
    public const string CREATED_FLASH = 'api-key-created';

    public function __construct(
        private readonly ListApiKeysHandler $listApiKeys,
    ) {
    }

    public function __invoke(Request $request, bool $openCreateDialog = false): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $created = null;
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $payload = $session->getFlashBag()->get(self::CREATED_FLASH)[0] ?? null;
            if (\is_array($payload) && \is_string($payload['name'] ?? null) && \is_string($payload['secret'] ?? null)) {
                $created = ['name' => $payload['name'], 'secret' => $payload['secret']];
            }
        }

        $response = $this->render('@OAuth/list_api_keys.html.twig', [
            'view' => ($this->listApiKeys)(new ListApiKeysCommand($user)),
            'createForm' => $this->getInjectedFormView($request, self::CREATE_FORM)
                ?? $this->createForm(CreateApiKeyFormType::class, new CreateApiKeyRequest(), ['action' => $this->generateUrl('app_account_api_key_create')])->createView(),
            'created' => $created,
            'openCreateDialog' => $openCreateDialog,
        ]);
        if (null !== $created) {
            $response->headers->addCacheControlDirective('no-store');
        }

        return $response;
    }
}
