<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\ComparisonPages;
use App\Module\Landing\ValueObject\ComparisonPage;
use App\Site\SiteHost;

final readonly class ListComparisonsHandler
{
    public function __construct(
        private ComparisonPages $comparisonPages,
        private SiteHost $siteHost,
    ) {
    }

    /** @return list<ComparisonPage>|null null when the instance has no site host */
    public function __invoke(): ?array
    {
        return $this->siteHost->isSet() ? $this->comparisonPages->all() : null;
    }
}
