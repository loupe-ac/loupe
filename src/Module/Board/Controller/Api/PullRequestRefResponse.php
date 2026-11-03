<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** A pull request link in the row of a card list: the link, without the forge state and the agent review. */
#[OA\Schema(required: ['pullRequestId', 'url', 'forge', 'repository', 'number'])]
final readonly class PullRequestRefResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $pullRequestId,
        public string $url,
        public string $forge,

        #[OA\Property(nullable: true)]
        public ?string $repository,

        #[OA\Property(nullable: true)]
        public ?int $number,
    ) {
    }
}
