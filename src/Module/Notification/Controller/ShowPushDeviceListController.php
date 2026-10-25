<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ListPushDevicesCommand;
use App\Module\Notification\Command\ListPushDevicesHandler;
use App\Module\Notification\Service\DeviceCookie;
use App\Module\Notification\Service\DeviceLabel;
use App\Module\Notification\Service\PushAvailability;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The turbo frame of the device list. The settings page renders the same
 * partial, and a Turbo reload of the frame fetches this route. The list holds
 * the devices of the signed-in user alone, so no voter is needed.
 */
#[Route(
    '/account/notifications/device-list',
    name: 'app_account_notifications_device_list',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowPushDeviceListController extends AppController
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

        return $this->render('@Notification/_push_device_list.html.twig', [
            'view' => $view,
            'pushControls' => $view->available && null !== $this->availability->keys(),
            'previewState' => null,
            'thisBrowserLabel' => DeviceLabel::fromUserAgent($request->headers->get('User-Agent')),
        ]);
    }
}
