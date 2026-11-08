<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\FeaturePages;
use App\Site\SiteHost;

final readonly class ShowFeatureHandler
{
    public function __construct(
        private FeaturePages $featurePages,
        private SiteHost $siteHost,
    ) {
    }

    /** @return ShowFeatureView|null null when the instance has no site host or the slug names no feature */
    public function __invoke(ShowFeatureCommand $command): ?ShowFeatureView
    {
        $page = $this->siteHost->isSet() ? $this->featurePages->find($command->slug) : null;
        if (null === $page) {
            return null;
        }

        return new ShowFeatureView($page, array_values(array_filter(
            $this->featurePages->all(),
            static fn ($other): bool => $other->slug !== $page->slug,
        )));
    }
}
