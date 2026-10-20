<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Entity\User;
use App\Site\SignedInCookie;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Tells the site host that this browser is signed in. It runs after the
 * listener that enables the remember-me badge. A remember-me re-login has no
 * response to carry the cookie, and its first cookie is still there.
 */
#[AsEventListener(priority: -100)]
final readonly class SetSignedInCookieOnLoginSuccess
{
    private const string INTERACTIVE_FIREWALL = 'main';

    public function __construct(
        private SignedInCookie $signedInCookie,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $response = $event->getResponse();
        if (self::INTERACTIVE_FIREWALL !== $event->getFirewallName() || !$user instanceof User || null === $response) {
            return;
        }

        $badge = $event->getPassport()->getBadge(RememberMeBadge::class);
        $cookie = $this->signedInCookie->issue(
            admin: \in_array('ROLE_ADMIN', $user->getRoles(), true),
            persistent: $badge instanceof RememberMeBadge && $badge->isEnabled(),
            secure: $event->getRequest()->isSecure(),
        );
        if (null !== $cookie) {
            $response->headers->setCookie($cookie);
        }
    }
}
