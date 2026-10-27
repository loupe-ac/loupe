<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\FaqPage;
use App\Module\Landing\ValueObject\FaqContent;
use App\Site\SiteHost;

final readonly class ShowFaqHandler
{
    public function __construct(
        private FaqPage $faqPage,
        private SiteHost $siteHost,
    ) {
    }

    /** @return FaqContent|null null when the instance has no site host */
    public function __invoke(): ?FaqContent
    {
        return $this->siteHost->isSet() ? $this->faqPage->content() : null;
    }
}
