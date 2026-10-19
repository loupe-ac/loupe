<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\LabelTone;

final readonly class Slot
{
    /**
     * @param string     $label     a translation key
     * @param ?string    $stageWork the kind of work that does the stage of this slot, or null when the slot names none
     * @param ?LabelTone $tone      the colour of the column a new project gets for this slot, or null for neutral
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $stageWork = null,
        public ?LabelTone $tone = null,
    ) {
    }
}
