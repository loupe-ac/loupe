<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

/** The small cache entry of a link: the version of the kept image and where its PNG lives. */
final readonly class PublicPreviewEntry
{
    public function __construct(
        public string $version,
        public string $hash,
        /** Unix time of the last write of the PNG entry. */
        public int $pngWrittenAt,
    ) {
    }
}
