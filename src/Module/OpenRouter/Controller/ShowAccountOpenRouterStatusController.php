<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Command\ShowAccountOpenRouterCommand;
use App\Module\OpenRouter\Command\ShowAccountOpenRouterHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The turbo frame of the account key status. The page renders the same partial,
 * and a Turbo reload of the frame fetches this route. It holds the key of the
 * signed-in user alone, so no voter is needed.
 */
#[Route(
    '/account/openrouter/status',
    name: 'app_account_openrouter_status',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowAccountOpenRouterStatusController extends AppController
{
    public function __construct(
        private readonly ShowAccountOpenRouterHandler $showAccount,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@OpenRouter/_account_key_status.html.twig', [
            'view' => ($this->showAccount)(new ShowAccountOpenRouterCommand($user)),
        ]);
    }
}
