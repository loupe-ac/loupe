<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** A button of a guide step. Loupe builds the address, never GitHub. */
final readonly class GuideLink
{
    /** @param array<string, string> $labelParameters */
    public function __construct(
        public string $labelKey,
        public string $href,
        public bool $external,
        public array $labelParameters = [],
    ) {
    }
}
