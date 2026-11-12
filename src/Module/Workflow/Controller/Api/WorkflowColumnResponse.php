<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Api;

use OpenApi\Attributes as OA;

/** A board column, with the workflow slot that links to it. */
#[OA\Schema(required: ['slug', 'label', 'slot', 'backlog', 'terminal'])]
final readonly class WorkflowColumnResponse
{
    public function __construct(
        #[OA\Property(description: 'The slug of the column.')]
        public string $slug,

        #[OA\Property(description: 'The label of the column, in the language of the request.')]
        public string $label,

        #[OA\Property(description: 'The key of the workflow slot that links to the column. Null when no slot links to it.', nullable: true)]
        public ?string $slot,

        #[OA\Property(description: 'True for the backlog column.')]
        public bool $backlog,

        #[OA\Property(description: 'True for a column that holds finished work.')]
        public bool $terminal,
    ) {
    }
}
