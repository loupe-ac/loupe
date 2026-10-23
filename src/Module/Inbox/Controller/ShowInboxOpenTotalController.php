<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\ShowInboxOpenTotalCommand;
use App\Module\Inbox\Command\ShowInboxOpenTotalHandler;
use App\Module\Inbox\Service\InboxAvailability;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The open total alone, for the frame the tab-count controller reads. */
#[Route(
    '/inbox/open-total',
    name: 'app_inbox_open_total',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowInboxOpenTotalController extends AppController
{
    public function __construct(
        private readonly ShowInboxOpenTotalHandler $showOpenTotal,
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

        $response = $this->render('@Inbox/_open_total.html.twig', [
            'total' => ($this->showOpenTotal)(new ShowInboxOpenTotalCommand($user)),
        ]);
        // A reload follows a change, so a cached total would show the old one.
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
