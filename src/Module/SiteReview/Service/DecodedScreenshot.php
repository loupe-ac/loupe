<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

/** An image that ScreenshotDecoder accepted. */
final readonly class DecodedScreenshot
{
    public function __construct(
        public string $contentType,
        public string $bytes,
        public int $size,
        public int $width,
        public int $height,
        public string $sha256,
    ) {
    }
}
