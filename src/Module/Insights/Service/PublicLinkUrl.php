<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Site\SiteHost;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The address an owner gives out for a public link: on the site host when the instance has one, and on the app host otherwise. */
final readonly class PublicLinkUrl
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private SiteHost $siteHost,
    ) {
    }

    public function forCode(string $code): string
    {
        if ($this->siteHost->isSplit()) {
            return $this->siteHost->origin().$this->urls->generate('app_public_link', ['code' => $code]);
        }

        return $this->urls->generate('app_public_link', ['code' => $code], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
