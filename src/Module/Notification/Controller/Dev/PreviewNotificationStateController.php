<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ListNotificationPreferencesCommand;
use App\Module\Notification\Command\ListNotificationPreferencesHandler;
use App\Module\Notification\Command\ListPushDevicesCommand;
use App\Module\Notification\Command\ListPushDevicesHandler;
use App\Module\Notification\Command\ListPushDevicesView;
use App\Module\Notification\Form\ChangeNotificationPreferencesFormType;
use App\Module\Notification\Form\ChangeNotificationPreferencesRequest;
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
    requirements: ['state' => 'push-unavailable|preferences-on|blocked|ios-tab'],
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewNotificationStateController extends AppController
{
    public function __construct(
        private readonly ListPushDevicesHandler $listDevices,
        private readonly ListNotificationPreferencesHandler $listPreferences,
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

        if ('preferences-on' === $state) {
            $view = new ListPushDevicesView($view->devices, $view->currentDeviceId, null, true);
        }

        $preferences = ($this->listPreferences)(new ListNotificationPreferencesCommand($user));
        $preferencesForm = $this->createForm(
            ChangeNotificationPreferencesFormType::class,
            ChangeNotificationPreferencesRequest::fromCells($preferences->cells),
            ['action' => $this->generateUrl('app_account_notifications_preferences'), 'disabled_cells' => !$view->available],
        );

        return $this->render('@Notification/preview_notification_state.html.twig', [
            'view' => $view,
            'preferences' => $preferences,
            'preferencesForm' => $preferencesForm,
            'vapidPublicKey' => 'preferences-on' !== $state && $view->available ? $this->availability->keys()?->publicKey : null,
            'previewState' => \in_array($state, ['push-unavailable', 'preferences-on'], true) ? null : $state,
            'isAdmin' => $this->isGranted('ROLE_ADMIN'),
            'thisBrowserLabel' => DeviceLabel::fromUserAgent($request->headers->get('User-Agent')),
        ]);
    }
}
