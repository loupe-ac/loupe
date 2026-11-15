<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\ShowAccountSettingsCommand;
use App\Module\Account\Command\ShowAccountSettingsHandler;
use App\Module\Account\Entity\User;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The export list of the data page, which the open page reads again after a signal. */
#[Route(
    '/account/data/frame',
    name: 'app_account_data_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowAccountDataFrameController extends AppController
{
    public function __construct(
        private readonly ShowAccountSettingsHandler $handler,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@Account/_account_data_frame.html.twig', [
            'view' => ($this->handler)(new ShowAccountSettingsCommand($user)),
        ]);
    }
}
