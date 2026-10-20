<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Site\SignedInCookie;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Runs after the default logout listener, which sets the response that carries the cookie. */
#[AsEventListener(priority: -100)]
final readonly class ClearSignedInCookieOnLogout
{
    public function __construct(
        private SignedInCookie $signedInCookie,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $cookie = $this->signedInCookie->clear($event->getRequest()->isSecure());
        if (null !== $cookie) {
            $event->getResponse()?->headers->setCookie($cookie);
        }
    }
}
