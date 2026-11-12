<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use OpenApi\Attributes as OA;

/** One readiness check. */
#[OA\Schema(required: ['key', 'done', 'status'])]
final readonly class ReadinessRowResponse
{
    public function __construct(
        #[OA\Property(description: 'The key of the check, such as `agent` or `bridge`.')]
        public string $key,

        #[OA\Property(description: 'Whether the check is done.')]
        public bool $done,

        #[OA\Property(description: 'A short status line, in the language of the request.')]
        public string $status,
    ) {
    }
}
