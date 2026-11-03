<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\CreateApiKeyCommand;
use App\Module\OAuth\Command\CreateApiKeyHandler;
use App\Module\OAuth\Form\CreateApiKeyFormType;
use App\Module\OAuth\Form\CreateApiKeyRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The new key travels in one flash to the page that shows it once. It is never logged or audited. */
#[Route(
    '/account/api-keys',
    name: 'app_account_api_key_create',
    methods: ['POST'],
)]
final class CreateApiKeyController extends AppController
{
    public function __construct(
        private readonly CreateApiKeyHandler $createApiKey,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $form = $this->createForm(CreateApiKeyFormType::class, new CreateApiKeyRequest(), ['action' => $this->generateUrl('app_account_api_key_create')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $view = ($this->createApiKey)(new CreateApiKeyCommand($user, $data->name ?? '', $data->expiry));
                $this->addFlash(ListApiKeysController::CREATED_FLASH, ['name' => $view->key->name, 'secret' => $view->secret]);

                return $this->redirectToRoute('app_account_api_keys');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward(ListApiKeysController::class, [ListApiKeysController::CREATE_FORM => $form->createView()])
            ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
