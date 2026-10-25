<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\DismissPushDeviceWarningCommand;
use App\Module\Notification\Command\DismissPushDeviceWarningHandler;
use App\Module\Notification\Service\LocalPath;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * The handler looks the device up among the signed-in user's own, so no voter is needed.
 * The optional return field names the page the form sat on. Only a path on this site is followed.
 */
#[CsrfToken('submit', parameter: 'token')]
#[Route(
    '/account/notifications/devices/{id}/dismiss-warning',
    name: 'app_account_notifications_device_dismiss_warning',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
final class DismissPushDeviceWarningController extends AppController
{
    public function __construct(
        private readonly DismissPushDeviceWarningHandler $dismissWarning,
    ) {
    }

    public function __invoke(Request $request, Uuid $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $path = LocalPath::from($request->request->getString('return'));

        ($this->dismissWarning)(new DismissPushDeviceWarningCommand($user, $id));

        return null === $path ? $this->redirectToRoute('app_account_notifications') : $this->redirect($path);
    }
}
