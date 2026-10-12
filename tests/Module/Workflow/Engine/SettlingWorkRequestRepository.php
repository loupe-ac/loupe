<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkSubject;

/** Settles the live request right after the open reads it, as when that request ends before the engine reads the answer. */
final class SettlingWorkRequestRepository extends WorkRequestRepository
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $settle = null;

    #[\Override]
    public function liveRuleId(WorkSubject $subject, string $kind): ?string
    {
        $ruleId = parent::liveRuleId($subject, $kind);
        if (null !== $ruleId && null !== $this->settle) {
            ($this->settle)();
        }

        return $ruleId;
    }
}
