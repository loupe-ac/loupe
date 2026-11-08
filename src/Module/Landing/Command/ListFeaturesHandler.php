<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\FeaturePages;
use App\Module\Landing\ValueObject\FeaturePage;
use App\Site\SiteHost;

final readonly class ListFeaturesHandler
{
    public function __construct(
        private FeaturePages $featurePages,
        private SiteHost $siteHost,
    ) {
    }

    /** @return list<FeaturePage>|null null when the instance has no site host */
    public function __invoke(): ?array
    {
        return $this->siteHost->isSet() ? $this->featurePages->all() : null;
    }
}
