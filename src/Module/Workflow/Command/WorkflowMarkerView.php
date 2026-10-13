<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowMarkerView
{
    /**
     * @param string                           $labelKey          a translation key
     * @param string                           $kind              a MarkerKind value
     * @param list<string>                     $slotLabelKeys     translation keys for the slots the marker applies in, none for every open column
     * @param list<WorkflowConditionGroupView> $whenGroups        the conditions of `when`, grouped by source in first-seen order
     * @param list<string>                     $missingConditions the keys of the conditions this instance no longer has
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $kind,
        public array $slotLabelKeys,
        public bool $fallback,
        public array $whenGroups,
        public array $missingConditions,
    ) {
    }
}
