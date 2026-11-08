<?php

declare(strict_types=1);

namespace App\Module\Landing\ValueObject;

/** One text block of a feature page. */
final readonly class FeatureBlock
{
    public function __construct(
        public string $title,
        public string $text,
    ) {
    }
}
