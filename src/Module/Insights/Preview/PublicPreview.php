<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

/** A preview image whose version the drawer confirmed. */
final readonly class PublicPreview
{
    public function __construct(
        public string $version,
        public string $png,
        /** True when another request drew it while this one waited for the lock. */
        public bool $reused = false,
    ) {
    }
}
