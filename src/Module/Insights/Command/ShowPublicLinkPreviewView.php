<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

/** The preview image of a link, or no image when the generic Loupe image stands in. */
final readonly class ShowPublicLinkPreviewView
{
    public function __construct(
        /** Null when no confirmed image exists. */
        public ?string $png,
        /** kept, inline, unsettled or fallback. */
        public string $source,
    ) {
    }
}
