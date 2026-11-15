<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

final readonly class ShowArtifactLiveBandView
{
    public function __construct(
        /** The newer version the page can show, or null when the page shows the latest. */
        public ?int $newerVersion,
        /** True when the artifact is archived and the page was rendered live. */
        public bool $archived,
        /** True when the artifact is live and the page was rendered archived. */
        public bool $restored,
    ) {
    }
}
