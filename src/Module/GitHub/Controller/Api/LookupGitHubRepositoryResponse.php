<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller\Api;

use OpenApi\Attributes as OA;

/** The standing of one repository path. */
#[OA\Schema(required: ['path', 'status'])]
final readonly class LookupGitHubRepositoryResponse
{
    public function __construct(
        #[OA\Property(description: 'The path that was asked, as owner/name.')]
        public string $path,

        #[OA\Property(description: '`in-project`: the project holds the repository through the App. `free`: the App reaches it and no project holds it. `other-project`: another project holds it. `not-shared`: the App does not reach it.', enum: ['in-project', 'free', 'other-project', 'not-shared'])]
        public string $status,
    ) {
    }
}
