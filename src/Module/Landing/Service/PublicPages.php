<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use App\Module\Account\Service\RegistrationGate;
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

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private HomePageGate $homePageGate,
        private RegistrationGate $registrationGate,

        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    public function isPublic(?string $route): bool
    {
        return null !== $route && \array_key_exists($route, self::PAGES);
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

        return $urls;
    }

    /** Prefixes the configured address, never the request host, which a client chooses. */
    public function absoluteUrl(string $path): string
    {
        return rtrim($this->appUrl, '/').$path;
    }

    private function isRendered(string $route): bool
    {
        return match ($route) {
            'app_home' => $this->homePageGate->shows(),
            'app_waitlist_join' => $this->registrationGate->allowsNewAccounts() && !$this->registrationGate->isOpen(),
            default => true,
        };
    }
}
