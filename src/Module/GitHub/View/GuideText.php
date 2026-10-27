<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** One sentence of a guide step, as a translation key and its parameters. */
final readonly class GuideText
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public string $key,
        public array $parameters = [],
    ) {
    }
}
