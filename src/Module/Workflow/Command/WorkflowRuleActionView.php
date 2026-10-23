<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowRuleActionView
{
    /**
     * @param string  $actionKey a translation key that takes %target% and %kind%
     * @param ?string $targetKey a translation key for the destination of a move
     * @param ?string $kind      the work kind of a request, or the write of a forge write
     */
    public function __construct(
        public string $actionKey,
        public ?string $targetKey,
        public ?string $kind,
    ) {
    }
}
