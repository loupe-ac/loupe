<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Routing\PinnedUrlGenerator;
use App\Site\SignedInCookie;
use App\Site\SiteHost;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The take down address of a shared page, for the viewer who is an admin. On the site host the page has no
 * session, so the signed-in cookie answers. On the app host the session answers.
 */
final class PublicLinkAdminBarExtension extends AbstractExtension
{
    public function __construct(
        private readonly SignedInCookie $signedInCookie,
        private readonly SiteHost $siteHost,
        private readonly RequestStack $requestStack,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly PinnedUrlGenerator $appUrls,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_link_takedown_url', $this->takedownUrl(...)),
        ];
    }

    /** The address on the app host, or null when the viewer is not known to be an admin. */
    public function takedownUrl(string $code): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        $admin = $this->siteHost->isSiteRequest($request->getHost())
            ? SignedInCookie::ADMIN === $this->signedInCookie->signal($request)
            : $this->authorization->isGranted('ROLE_ADMIN');

        return $admin ? $this->appUrls->generate('app_admin_public_links_take_down_confirm', ['code' => $code]) : null;
    }
}
