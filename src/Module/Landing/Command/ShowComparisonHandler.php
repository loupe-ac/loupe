<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\ComparisonPages;
use App\Site\SiteHost;

final readonly class ShowComparisonHandler
{
    public function __construct(
        private ComparisonPages $comparisonPages,
        private SiteHost $siteHost,
    ) {
    }

    /** @return ShowComparisonView|null null when the instance has no site host or the slug names no page */
    public function __invoke(ShowComparisonCommand $command): ?ShowComparisonView
    {
        $page = $this->siteHost->isSet() ? $this->comparisonPages->find($command->slug) : null;
        if (null === $page) {
            return null;
        }

        return new ShowComparisonView($page, array_values(array_filter(
            $this->comparisonPages->all(),
            static fn ($other): bool => $other->slug !== $page->slug,
        )));
    }
}
