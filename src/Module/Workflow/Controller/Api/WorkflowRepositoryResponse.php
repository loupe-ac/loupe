<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Api;

use OpenApi\Attributes as OA;

/** A repository of the project, as a repository setting names it. */
#[OA\Schema(required: ['path', 'forge'])]
final readonly class WorkflowRepositoryResponse
{
    public function __construct(
        #[OA\Property(description: 'The path of the repository on its forge, such as `owner/name`.')]
        public string $path,

        #[OA\Property(description: 'The forge that hosts the repository, such as `github`.')]
        public string $forge,
    ) {
    }
}
