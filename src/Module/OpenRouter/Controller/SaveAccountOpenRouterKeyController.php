<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Command\SaveOpenRouterKeyCommand;
use App\Module\OpenRouter\Command\SaveOpenRouterKeyHandler;
use App\Module\OpenRouter\Form\OpenRouterKeyFormType;
use App\Module\OpenRouter\Form\OpenRouterKeyRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The handler writes the account key of the signed-in user alone, so no voter is needed. */
#[Route(
    '/account/openrouter',
    name: 'app_account_openrouter_save',
    methods: ['POST'],
)]
final class SaveAccountOpenRouterKeyController extends AppController
{
    public function __construct(
        private readonly SaveOpenRouterKeyHandler $saveKey,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $form = $this->createForm(OpenRouterKeyFormType::class, new OpenRouterKeyRequest());
        $form->handleRequest($request);
        $notManagementKey = false;

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            \assert($data instanceof OpenRouterKeyRequest);

            try {
                ($this->saveKey)(new SaveOpenRouterKeyCommand($user, $data->secret ?? throw new \LogicException('The key is required after form validation.')));
                $this->addFlash('success', $this->translator->trans('openrouter.flash.saved'));

                return $this->redirectToRoute('app_account_openrouter');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
                $notManagementKey = 'openrouter.error.not_management_key' === ($e->errors['secret'] ?? null);
            }
        }

        return $this->forward(ShowAccountOpenRouterController::class, [
            'keyForm' => $form->createView(),
            'notManagementKey' => $notManagementKey,
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
