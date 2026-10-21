<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

final readonly class StampDocsCommand
{
    public function __construct(
        /** The built docs, as the image holds them. */
        public string $source,
        /** The folder that nginx serves. The stamp replaces its content. */
        public string $target,
        /** The compiled app stylesheet that the bar and footer rules come from. */
        public string $stylesheet,
    ) {
    }
}
