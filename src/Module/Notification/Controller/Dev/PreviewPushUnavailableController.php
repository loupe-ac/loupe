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
use App\Module\Notification\Service\PushUnavailableReason;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only Notifications page with push not set up, for a signed preview
 * link. The real state needs the instance-wide flag off.
 */
#[Route(
    '/dev/notification-preview/push-unavailable',
    name: 'dev_notification_preview_push_unavailable',
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewPushUnavailableController extends AppController
{
    public function __construct(
        private readonly ListPushDevicesHandler $listDevices,
        private readonly DeviceCookie $deviceCookie,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->listDevices)(new ListPushDevicesCommand($user, $this->deviceCookie->currentHash($request)));

        return $this->render('@Notification/preview_push_unavailable.html.twig', [
            'view' => new ListPushDevicesView($view->devices, $view->currentDeviceId, PushUnavailableReason::FlagOff, false),
            'vapidPublicKey' => null,
            'isAdmin' => $this->isGranted('ROLE_ADMIN'),
            'thisBrowserLabel' => DeviceLabel::fromUserAgent($request->headers->get('User-Agent')),
        ]);
    }
}
