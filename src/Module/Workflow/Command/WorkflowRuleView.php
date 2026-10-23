<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowRuleView
{
    /**
     * @param string                                 $appliesToKey      a translation key for the slot or the column flag the rule watches
     * @param non-empty-list<WorkflowRuleActionView> $actions           the actions of the rule, in the order they run
     * @param list<WorkflowConditionGroupView>       $whenGroups        the conditions of `when`, grouped by source in first-seen order
     * @param list<WorkflowConditionGroupView>       $untilGroups       the conditions of the `until` of a pause, grouped the same way
     * @param list<WorkflowConditionGroupView>       $refillGroups      the conditions of the `refill` of a request with a limit, grouped the same way
     * @param list<WorkflowConditionGroupView>       $cancelWhenGroups  the conditions of the `cancelWhen` of a request, grouped the same way
     * @param list<string>                           $missingConditions the keys of the conditions this instance no longer has
     * @param ?string                                $missingAction     the name of the action this version does not know, or null
     */
    public function __construct(
        public string $id,
        public string $appliesToKey,
        public array $actions,
        public array $whenGroups,
        public array $untilGroups,
        public array $refillGroups,
        public array $missingConditions,
        public ?string $missingAction = null,
        public array $cancelWhenGroups = [],
    ) {
    }
}
