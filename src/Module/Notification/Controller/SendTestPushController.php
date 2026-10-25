<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\SendTestPushCommand;
use App\Module\Notification\Command\SendTestPushHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** The handler looks the device up among the signed-in user's own, so no voter is needed. */
#[CsrfToken('submit', parameter: 'token')]
#[Route(
    '/account/notifications/devices/{id}/test',
    name: 'app_account_notifications_device_test',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
final class SendTestPushController extends AppController
{
    public function __construct(
        private readonly SendTestPushHandler $sendTestPush,
        private readonly TranslatorInterface $translator,

        #[Autowire(service: 'limiter.notification_test_push')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(Uuid $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        if (!$this->limiter->create('user:'.$user->getUserIdentifier())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many test pushes.');
        }

        try {
            ($this->sendTestPush)(new SendTestPushCommand($user, $id));
            $this->addFlash('success', $this->translator->trans('notification.device.flash.test_sent'));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans($e->errors['device'] ?? array_first($e->errors)));
        }

        return $this->redirectToRoute('app_account_notifications');
    }
}
