<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

/** One card that holds a link to an artifact, as the viewer lists it. */
final readonly class LinkedCard
{
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
    ) {
    }
}
