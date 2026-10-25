<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ListOpenPushTagsCommand;
use App\Module\Notification\Command\ListOpenPushTagsHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** A page load reads this once to close the notifications of asks that have closed. Only the signed-in user's own tags come back. */
#[Route(
    '/account/notifications/open-tags',
    name: 'app_account_notifications_open_tags',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ListOpenPushTagsController extends AppController
{
    public function __construct(
        private readonly ListOpenPushTagsHandler $listOpenTags,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $response = new JsonResponse(['tags' => ($this->listOpenTags)(new ListOpenPushTagsCommand($user))->tags]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
