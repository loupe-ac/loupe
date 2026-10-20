<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** A finished image that travels with a note, as base64 in the JSON body. */
final class ScreenshotInput
{
    public function __construct(
        #[Assert\Choice(choices: ['image/jpeg', 'image/png'])]
        #[Assert\NotBlank]
        public ?string $contentType = null,

        // No length cap here: the decoder owns the size rule, so a client gets
        // the same `screenshot_too_large` answer whatever the length.
        #[Assert\NotBlank]
        public ?string $data = null,
    ) {
    }
}
