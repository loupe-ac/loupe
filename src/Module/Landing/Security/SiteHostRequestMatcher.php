<?php

declare(strict_types=1);

namespace App\Module\Landing\Security;

use App\Site\SiteHost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/** Selects the firewall that reads no session, for every request to a site host that differs from the app host. */
final readonly class SiteHostRequestMatcher implements RequestMatcherInterface
{
    public function __construct(
        private SiteHost $siteHost,
    ) {
    }

    #[\Override]
    public function matches(Request $request): bool
    {
        return $this->siteHost->isSiteRequest($request->getHost());
    }
}
