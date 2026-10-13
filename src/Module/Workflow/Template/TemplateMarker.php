<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Expression\Expression;

/** A card marker the template declares. */
final readonly class TemplateMarker
{
    /**
     * @param string       $label    a translation key
     * @param ?string      $remedy   a translation key for what a person can do about the marker
     * @param list<string> $slots    the slots the marker applies in, or none for every column that is not terminal
     * @param bool         $fallback the marker counts only when no other marker of a state kind matches
     */
    public function __construct(
        public string $key,
        public MarkerKind $kind,
        public string $label,
        public ?string $remedy,
        public array $slots,
        public Expression $when,
        public bool $fallback = false,
    ) {
    }
}
