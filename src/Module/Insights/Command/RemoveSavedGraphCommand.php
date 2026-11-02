<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;

final readonly class RemoveSavedGraphCommand
{
    public function __construct(
        public InsightsSavedGraph $graph,
        /** The code of a preview from a tool. The web page has no code, because its dialog asks the person. */
        public ?string $confirmation = null,
    ) {
    }
}
