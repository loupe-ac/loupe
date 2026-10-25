<?php

declare(strict_types=1);

namespace App\Module\Notification\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ForgetPushDeviceCommand;
use App\Module\Notification\Command\ForgetPushDeviceHandler;
use App\Module\Notification\Service\DeviceCookie;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Runs after the default logout listener, which sets the response that carries the cookie. */
#[AsEventListener(priority: -100)]
final readonly class ForgetPushDeviceOnLogout
{
    public function __construct(
        private ForgetPushDeviceHandler $forgetDevice,
        private DeviceCookie $deviceCookie,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $hash = $this->deviceCookie->currentHash($request);
        if (null === $hash) {
            return;
        }

        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            ($this->forgetDevice)(new ForgetPushDeviceCommand($user, $hash));
        }

        $event->getResponse()?->headers->setCookie($this->deviceCookie->expire($request->isSecure()));
    }
}
