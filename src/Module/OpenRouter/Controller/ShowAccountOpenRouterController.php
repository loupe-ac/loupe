<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Command\ShowAccountOpenRouterCommand;
use App\Module\OpenRouter\Command\ShowAccountOpenRouterHandler;
use App\Module\OpenRouter\Form\OpenRouterKeyFormType;
use App\Module\OpenRouter\Form\OpenRouterKeyRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The page holds the account key of the signed-in user alone, so no voter is needed. */
#[Route(
    '/account/openrouter',
    name: 'app_account_openrouter',
    methods: ['GET'],
)]
final class ShowAccountOpenRouterController extends AppController
{
    public function __construct(
        private readonly ShowAccountOpenRouterHandler $showAccount,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->showAccount)(new ShowAccountOpenRouterCommand($user));
        $injectedForm = $this->getInjectedFormView($request, 'keyForm');
        $keyForm = $injectedForm ?? $this->createForm(OpenRouterKeyFormType::class, new OpenRouterKeyRequest())->createView();

        return $this->render('@OpenRouter/show_account_openrouter.html.twig', [
            'view' => $view,
            'keyForm' => $keyForm,
            'replaceOpen' => null !== $injectedForm || $request->query->has('replace'),
            'notManagementKey' => true === $request->attributes->get('notManagementKey'),
        ]);
    }
}
