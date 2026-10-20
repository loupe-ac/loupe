<?php

declare(strict_types=1);

namespace App\Twig;

use App\Routing\PinnedUrlGenerator;
use App\Site\SiteHost;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Links between the site host and the app host. They name the configured hosts, never the request host. */
final class SiteUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly PinnedUrlGenerator $appUrls,
        private readonly SiteHost $siteHost,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_url', $this->appUrl(...)),
            new TwigFunction('site_url', $this->siteUrl(...)),
        ];
    }

    /** @param array<string, mixed> $parameters */
    public function appUrl(string $route, array $parameters = []): string
    {
        return $this->appUrls->generate($route, $parameters);
    }

    /** The path alone when the site shares the app host, or when the instance has no site host. */
    public function siteUrl(string $path): string
    {
        return $this->siteHost->isSplit() ? $this->siteHost->origin().$path : $path;
    }
}
