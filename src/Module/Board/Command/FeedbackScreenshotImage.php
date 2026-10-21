<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

final readonly class FeedbackScreenshotImage
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
