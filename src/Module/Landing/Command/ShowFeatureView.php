<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\ValueObject\FeaturePage;

final readonly class ShowFeatureView
{
    /** @param list<FeaturePage> $others every feature except the shown one */
    public function __construct(
        public FeaturePage $page,
        public array $others,
    ) {
    }
}
