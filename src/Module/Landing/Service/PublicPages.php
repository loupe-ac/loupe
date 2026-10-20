<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use App\Module\Account\Service\RegistrationGate;
use App\Site\SiteHost;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The pages a signed-out visitor, a crawler and the analytics tag may reach. */
final readonly class PublicPages
{
    /** Route name to whether the sitemap lists the page. */
    private const array PAGES = [
        'app_home' => true,
        'app_privacy' => true,
        'app_terms' => true,
        'app_ai_policy' => true,
        'app_waitlist_join' => true,
        'app_login' => false,
        'app_register' => false,
    ];

    /** The routes a site host answers. Anything else gets the 404 page there. */
    private const array SITE_ROUTES = [
        'app_home',
        'app_privacy',
        'app_terms',
        'app_ai_policy',
        'app_waitlist_join',
        'app_waitlist_notify',
        'app_waitlist_modal',
        'app_sitemap',
        'app_robots',
        'app_site_docs_script',
        'ubermuda_health_check',
        'app_livez',
        '_preview_error',
    ];

    private const array LEGAL_ROUTES = ['app_privacy', 'app_terms', 'app_ai_policy'];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private SiteHost $siteHost,
        private RegistrationGate $registrationGate,

        #[Autowire(param: 'app.url')]
        private string $appUrl,

        #[Autowire(env: 'BLOG_PROXY_HOST')]
        private string $blogProxyHost,
    ) {
    }

    /** nginx serves /blog from the external host, so the app never routes it. */
    public function hasBlog(): bool
    {
        return '' !== $this->blogProxyHost;
    }

    public function isPublic(?string $route): bool
    {
        return null !== $route && \array_key_exists($route, self::PAGES);
    }

    public function isSiteRoute(?string $route): bool
    {
        return null !== $route && \in_array($route, self::SITE_ROUTES, true);
    }

    public function isLegalRoute(string $route): bool
    {
        return \in_array($route, self::LEGAL_ROUTES, true);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map($this->urlGenerator->generate(...), array_keys(self::PAGES));
    }

    /** @return list<string> absolute URLs */
    public function sitemapUrls(): array
    {
        $urls = [];
        foreach (self::PAGES as $route => $listed) {
            if ($listed && $this->isRendered($route)) {
                $urls[] = $this->absoluteUrl($this->urlGenerator->generate($route));
            }
        }
        if ($this->hasBlog()) {
            $urls[] = $this->absoluteUrl('/blog');
        }

        return $urls;
    }

    /** Prefixes the site origin, never the request host, which a client chooses. */
    public function absoluteUrl(string $path): string
    {
        return ($this->siteHost->origin() ?? rtrim($this->appUrl, '/')).$path;
    }

    private function isRendered(string $route): bool
    {
        return match ($route) {
            'app_home' => $this->siteHost->isSet(),
            'app_waitlist_join' => $this->registrationGate->allowsNewAccounts() && !$this->registrationGate->isOpen(),
            default => true,
        };
    }
}
