<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\ListConnectedAppsCommand;
use App\Module\OAuth\Command\ListConnectedAppsHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The connected apps and preview tokens of the account, which the open page reads again after a signal. */
#[Route(
    '/account/connected-apps/frame',
    name: 'app_account_connected_apps_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowConnectedAppsFrameController extends AppController
{
    public function __construct(
        private readonly ListConnectedAppsHandler $listConnectedApps,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@OAuth/_connected_apps_frame.html.twig', [
            'view' => ($this->listConnectedApps)(new ListConnectedAppsCommand($user)),
        ]);
    }
}
