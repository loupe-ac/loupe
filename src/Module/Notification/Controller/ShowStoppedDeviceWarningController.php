<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ListPushDeviceWarningsCommand;
use App\Module\Notification\Command\ListPushDeviceWarningsHandler;
use App\Module\Notification\Service\LocalPath;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The turbo frame of the stopped device warnings. A Turbo reload of the frame
 * fetches it, so the form takes its return path from the Referer: the page that
 * holds the frame is the page the user wants back.
 */
#[Route(
    '/account/notifications/warning',
    name: 'app_account_notifications_warning',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowStoppedDeviceWarningController extends AppController
{
    public function __construct(
        private readonly ListPushDeviceWarningsHandler $listWarnings,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->listWarnings)(new ListPushDeviceWarningsCommand($user));

        return $this->render('@Notification/_stopped_device_warning.html.twig', [
            'devices' => $view->devices,
            'returnPath' => LocalPath::ofSameOrigin($request->headers->get('Referer'), $request->getSchemeAndHttpHost()),
        ]);
    }
}
