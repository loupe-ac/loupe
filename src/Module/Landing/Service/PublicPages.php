<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use App\Module\Account\Service\RegistrationGate;
use App\Module\Landing\ValueObject\ComparisonPage;
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
        'app_compare_list' => true,
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
        'app_compare_list',
        'app_compare',
        'app_waitlist_join',
        'app_waitlist_notify',
        'app_waitlist_modal',
        'app_sitemap',
        'app_robots',
        'app_llms',
        'app_site_docs_script',
        'ubermuda_health_check',
        'app_livez',
        '_preview_error',
    ];

    /** The routes that have one copy, on the site host, when the instance splits its hosts. */
    private const array SITE_ONLY_ROUTES = ['app_privacy', 'app_terms', 'app_ai_policy', 'app_compare_list', 'app_compare'];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private SiteHost $siteHost,
        private RegistrationGate $registrationGate,
        private ComparisonPages $comparisonPages,

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
        return null !== $route && (\array_key_exists($route, self::PAGES) || 'app_compare' === $route);
    }

    public function isSiteRoute(?string $route): bool
    {
        return null !== $route && \in_array($route, self::SITE_ROUTES, true);
    }

    public function isSiteOnlyRoute(string $route): bool
    {
        return \in_array($route, self::SITE_ONLY_ROUTES, true);
    }

    /** True when the instance has a site host and at least one comparison page to show. */
    public function hasComparisons(): bool
    {
        return $this->siteHost->isSet() && [] !== $this->comparisonPages->all();
    }

    /** @return list<string> */
    public function paths(): array
    {
        return [
            ...array_map($this->urlGenerator->generate(...), array_keys(self::PAGES)),
            ...$this->comparisonPaths(),
        ];
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
        if ($this->siteHost->isSet()) {
            foreach ($this->comparisonPages->all() as $page) {
                $urls[] = $this->comparisonUrl($page);
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

    /** The absolute URL of a comparison page, on the site origin. */
    public function comparisonUrl(ComparisonPage $page): string
    {
        return $this->absoluteUrl($this->comparisonPath($page));
    }

    /** @return list<string> */
    private function comparisonPaths(): array
    {
        return array_map($this->comparisonPath(...), $this->comparisonPages->all());
    }

    private function comparisonPath(ComparisonPage $page): string
    {
        return $this->urlGenerator->generate('app_compare', ['slug' => $page->slug]);
    }

    private function isRendered(string $route): bool
    {
        return match ($route) {
            'app_home', 'app_compare_list' => $this->siteHost->isSet(),
            'app_waitlist_join' => $this->registrationGate->allowsNewAccounts() && !$this->registrationGate->isOpen(),
            default => true,
        };
    }
}
