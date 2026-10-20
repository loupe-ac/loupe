<?php

declare(strict_types=1);

namespace App\Site;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The host of the public site. An instance with a site host answers it with the
 * site pages alone, and every other host with the app.
 */
final readonly class SiteHost
{
    /** Null when the instance has no site host. */
    public ?string $host;

    private string $appUrl;

    private ?string $appHost;

    public function __construct(
        #[Autowire(env: 'default::SITE_HOST')]
        ?string $configured,

        #[Autowire(param: 'app.url')]
        string $appUrl,
    ) {
        $host = strtolower(trim($configured ?? ''));
        $this->host = '' === $host ? null : $host;
        $this->appUrl = rtrim($appUrl, '/');
        $appHost = parse_url($appUrl, \PHP_URL_HOST);
        $this->appHost = \is_string($appHost) ? strtolower($appHost) : null;
    }

    public function isSet(): bool
    {
        return null !== $this->host;
    }

    /** True when the site host is set and differs from the host of the app address. */
    public function isSplit(): bool
    {
        return null !== $this->host && $this->host !== $this->appHost;
    }

    public function owns(string $requestHost): bool
    {
        return null !== $this->host && strtolower($requestHost) === $this->host;
    }

    /** True when the request host plays the site role: the site host, or the one host of an unsplit instance. */
    public function servesSiteOn(string $requestHost): bool
    {
        return $this->isSet() && (!$this->isSplit() || $this->owns($requestHost));
    }

    /** True when the site host is set, differs from the app host, and the request reached it. */
    public function isSiteRequest(string $requestHost): bool
    {
        return $this->isSplit() && $this->owns($requestHost);
    }

    /** The address that site pages are served from, or null with no site host. */
    public function origin(): ?string
    {
        if (null === $this->host) {
            return null;
        }

        return $this->isSplit() ? 'https://'.$this->host : $this->appUrl;
    }
}
