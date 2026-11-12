<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Api;

use OpenApi\Attributes as OA;

/** The workflow template that a project follows. */
#[OA\Schema(required: ['key', 'version'])]
final readonly class WorkflowTemplateResponse
{
    public function __construct(
        #[OA\Property(description: 'The key of the template, such as `lifecycle`.')]
        public string $key,

        #[OA\Property(description: 'The version of the template that the project copy follows.')]
        public string $version,
    ) {
    }
}
