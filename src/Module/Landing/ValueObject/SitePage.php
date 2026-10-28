<?php

declare(strict_types=1);

namespace App\Module\Landing\ValueObject;

/** A content page of the public site, rendered from its Markdown file. */
final readonly class SitePage
{
    public function __construct(
        public string $title,
        public string $description,
        public string $html,
    ) {
    }
}
