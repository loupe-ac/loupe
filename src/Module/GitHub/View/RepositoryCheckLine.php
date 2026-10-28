<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** One line of the check list of a repository: a chip, a title and a detail. */
final readonly class RepositoryCheckLine
{
    /**
     * @param string               $modifier         the status chip modifier: failed, pending or ok
     * @param string               $stateKey         the translation key of the chip label
     * @param array<string,string> $titleParameters
     * @param array<string,string> $detailParameters
     * @param string|null          $gap              the gap code of a gap line, null for a line that fits
     */
    public function __construct(
        public string $modifier,
        public string $stateKey,
        public string $titleKey,
        public array $titleParameters,
        public string $detailKey,
        public array $detailParameters,
        public ?string $gap = null,
    ) {
    }
}
