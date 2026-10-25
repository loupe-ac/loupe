<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ListPushDevicesCommand;
use App\Module\Notification\Command\ListPushDevicesHandler;
use App\Module\Notification\Command\ListPushDevicesView;
use App\Module\Notification\Service\DeviceCookie;
use App\Module\Notification\Service\DeviceLabel;
use App\Module\Notification\Service\PushAvailability;
use App\Module\Notification\Service\PushUnavailableReason;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only Notifications page in one fixed state, for a signed preview link.
 * The real states hang on the instance flag or on the browser.
 */
#[Route(
    '/dev/notification-preview/{state}',
    name: 'dev_notification_preview',
    requirements: ['state' => 'push-unavailable|blocked|ios-tab'],
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewNotificationStateController extends AppController
{
    public function __construct(
        private readonly ListPushDevicesHandler $listDevices,
        private readonly DeviceCookie $deviceCookie,
        private readonly PushAvailability $availability,
    ) {
    }

    public function __invoke(Request $request, string $state): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->listDevices)(new ListPushDevicesCommand($user, $this->deviceCookie->currentHash($request)));
        if ('push-unavailable' === $state) {
            $view = new ListPushDevicesView($view->devices, $view->currentDeviceId, PushUnavailableReason::FlagOff, false);
        }

        return $this->render('@Notification/preview_notification_state.html.twig', [
            'view' => $view,
            'vapidPublicKey' => $view->available ? $this->availability->keys()?->publicKey : null,
            'previewState' => 'push-unavailable' === $state ? null : $state,
            'isAdmin' => $this->isGranted('ROLE_ADMIN'),
            'thisBrowserLabel' => DeviceLabel::fromUserAgent($request->headers->get('User-Agent')),
        ]);
    }
}
