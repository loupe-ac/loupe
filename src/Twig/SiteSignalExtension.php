<?php

declare(strict_types=1);

namespace App\Twig;

use App\Site\SignedInCookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** What a site page learns from the signed-in cookie, since it has no session. Both answer false on the app host. */
final class SiteSignalExtension extends AbstractExtension
{
    public function __construct(
        private readonly SignedInCookie $signedInCookie,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_signed_in', $this->signedIn(...)),
            new TwigFunction('site_admin', $this->admin(...)),
        ];
    }

    public function signedIn(): bool
    {
        return null !== $this->signedInCookie->signal($this->requestStack->getMainRequest());
    }

    public function admin(): bool
    {
        return SignedInCookie::ADMIN === $this->signedInCookie->signal($this->requestStack->getMainRequest());
    }
}
