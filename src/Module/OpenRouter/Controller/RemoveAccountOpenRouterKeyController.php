<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Command\RemoveOpenRouterKeyCommand;
use App\Module\OpenRouter\Command\RemoveOpenRouterKeyHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** The handler removes the account key of the signed-in user alone, so no voter is needed. */
#[CsrfToken('openrouter-key-remove')]
#[Route(
    '/account/openrouter/remove',
    name: 'app_account_openrouter_remove',
    methods: ['POST'],
)]
final class RemoveAccountOpenRouterKeyController extends AppController
{
    public function __construct(
        private readonly RemoveOpenRouterKeyHandler $removeKey,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        ($this->removeKey)(new RemoveOpenRouterKeyCommand($user));
        $this->addFlash('success', $this->translator->trans('openrouter.flash.removed'));

        return $this->redirectToRoute('app_account_openrouter');
    }
}
