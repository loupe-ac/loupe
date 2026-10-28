<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\AboutPage;
use App\Module\Landing\ValueObject\SitePage;
use App\Site\SiteHost;

final readonly class ShowSiteAboutHandler
{
    public function __construct(
        private AboutPage $aboutPage,
        private SiteHost $siteHost,
    ) {
    }

    /** @return SitePage|null null when the instance has no separate site host */
    public function __invoke(): ?SitePage
    {
        return $this->siteHost->isSplit() ? $this->aboutPage->page() : null;
    }
}
