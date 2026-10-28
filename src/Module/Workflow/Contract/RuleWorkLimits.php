<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Inbox asks this port what a rule with a work limit requests, so a work-limit pause can say what looped. */
interface RuleWorkLimits
{
    /** @return ?RuleWorkLimit null when the template of the project has no such rule, or the rule requests no work up to a limit */
    public function of(Uuid $projectId, Uuid $cardId, string $ruleId): ?RuleWorkLimit;
}
