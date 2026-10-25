<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ListPushDevicesCommand;
use App\Module\Notification\Command\ListPushDevicesHandler;
use App\Module\Notification\Service\DeviceCookie;
use App\Module\Notification\Service\PushAvailability;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The list holds the devices of the signed-in user alone, so no voter is needed. */
#[Route(
    '/account/notifications',
    name: 'app_account_notifications',
    methods: ['GET'],
)]
final class ShowNotificationSettingsController extends AppController
{
    public function __construct(
        private readonly ListPushDevicesHandler $listDevices,
        private readonly DeviceCookie $deviceCookie,
        private readonly PushAvailability $availability,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->listDevices)(new ListPushDevicesCommand($user, $this->deviceCookie->currentHash($request)));

        return $this->render('@Notification/show_notification_settings.html.twig', [
            'view' => $view,
            'vapidPublicKey' => $view->available ? $this->availability->keys()?->publicKey : null,
            'isAdmin' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }
}
