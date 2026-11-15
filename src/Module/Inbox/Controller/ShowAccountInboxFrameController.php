<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\ShowAccountInboxCommand;
use App\Module\Inbox\Command\ShowAccountInboxHandler;
use App\Module\Inbox\Service\InboxAvailability;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The project list of the account inbox, which the open page reads again after a signal. */
#[Route(
    '/account/inbox/frame',
    name: 'app_account_inbox_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowAccountInboxFrameController extends AppController
{
    public function __construct(
        private readonly ShowAccountInboxHandler $showAccountInbox,
        private readonly InboxAvailability $inbox,
    ) {
    }

    public function __invoke(): Response
    {
        $this->inbox->requireEnabled();

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@Inbox/_account_inbox_frame.html.twig', [
            'inbox' => ($this->showAccountInbox)(new ShowAccountInboxCommand($user)),
        ]);
    }
}
