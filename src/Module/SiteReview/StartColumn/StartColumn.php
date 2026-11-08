<?php

declare(strict_types=1);

namespace App\Module\SiteReview\StartColumn;

/** A column the widget offers as the start of a new card. The hint is null when the workflow has none. */
final readonly class StartColumn
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $hint,
    ) {
    }
}
