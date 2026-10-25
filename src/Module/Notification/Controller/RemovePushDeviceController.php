<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\RemovePushDeviceCommand;
use App\Module\Notification\Command\RemovePushDeviceHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** The handler looks the device up among the signed-in user's own, so no voter is needed. */
#[CsrfToken('submit', parameter: 'token')]
#[Route(
    '/account/notifications/devices/{id}/remove',
    name: 'app_account_notifications_device_remove',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
final class RemovePushDeviceController extends AppController
{
    public function __construct(
        private readonly RemovePushDeviceHandler $removeDevice,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Uuid $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        ($this->removeDevice)(new RemovePushDeviceCommand($user, $id));
        $this->addFlash('success', $this->translator->trans('notification.device.flash.removed'));

        return $this->redirectToRoute('app_account_notifications');
    }
}
