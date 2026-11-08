<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\PublicExperimentResult;

/** What the preview image of a link shows now: the kept result and its version. A graph that cannot be drawn has no result. */
final readonly class PublicPreviewSubject
{
    public function __construct(
        public string $version,
        public ?KeptGraphResult $graph = null,
        /** Whether the graph shows its legend. */
        public bool $legend = false,
        public ?PublicExperimentResult $experiment = null,
    ) {
    }
}
