<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Api;

use OpenApi\Attributes as OA;

/** One kind of work that the workflow can ask a bridge for. */
#[OA\Schema(required: ['kind', 'origin', 'rules', 'checks'])]
final readonly class WorkflowKindResponse
{
    /**
     * @param list<string> $rules
     * @param list<string> $checks
     */
    public function __construct(
        #[OA\Property(description: 'The kind of work.')]
        public string $kind,

        #[OA\Property(description: 'Whether the template or the app adds the kind.', enum: ['template', 'app'])]
        public string $origin,

        #[OA\Property(description: 'The ids of the rules that ask for the kind.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $rules,

        #[OA\Property(description: 'What the work needs from the project. A discovery run reads this to know what to look for.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $checks,
    ) {
    }
}
