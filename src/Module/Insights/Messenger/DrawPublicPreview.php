<?php

declare(strict_types=1);

namespace App\Module\Insights\Messenger;

/** Asks the insights worker to draw the preview image of one public link. */
final readonly class DrawPublicPreview
{
    public function __construct(
        public string $code,
    ) {
    }
}
