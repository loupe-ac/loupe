<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

/**
 * A finished image that travels with a note, as base64 in the JSON body. It
 * carries no constraints: ScreenshotDecoder owns every image rule, so each bad
 * image gets the same `screenshot_invalid` or `screenshot_too_large` answer.
 */
final class ScreenshotInput
{
    public function __construct(
        public ?string $contentType = null,
        public ?string $data = null,
    ) {
    }
}
