<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

/**
 * The image a new comment carries, as the client sent it. The Api request DTO
 * maps onto this, and ScreenshotDecoder checks it.
 */
final readonly class NewScreenshot
{
    public function __construct(
        public string $contentType,
        public string $base64,
    ) {
    }
}
