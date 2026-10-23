<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowConditionView
{
    /**
     * @param string  $params the parameters as "name: value" pairs, or '' when the condition takes none
     * @param ?string $fact   the name of the outermost named fact that holds the condition, or null when the template writes it in place
     */
    public function __construct(
        public string $key,
        public bool $negated,
        public string $params,
        public ?string $fact = null,
    ) {
    }
}
