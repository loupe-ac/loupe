<?php

declare(strict_types=1);

namespace App\Module\Landing\ValueObject;

/** One feature of the public site: its fixed facts and the copy of its Markdown file. */
final readonly class FeaturePage
{
    /**
     * @param list<string>       $mockups template names of the hero picture, then the three block pictures
     * @param list<FeatureBlock> $blocks
     */
    public function __construct(
        public string $key,
        public string $slug,
        public string $icon,
        public string $docsPath,
        public array $mockups,
        public string $label,
        public string $title,
        public string $lead,
        public string $description,
        public array $blocks,
    ) {
    }
}
