<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

final readonly class Slot
{
    /**
     * @param string  $label     a translation key
     * @param ?string $stageWork the kind of work that does the stage of this slot, or null when the slot names none
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $stageWork = null,
    ) {
    }
}
