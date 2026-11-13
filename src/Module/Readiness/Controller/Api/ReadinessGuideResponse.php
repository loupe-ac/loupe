<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use OpenApi\Attributes as OA;

/** Whether the Workshop shows the readiness guide, and when it was hidden. */
#[OA\Schema(required: ['shown', 'hiddenAt'])]
final readonly class ReadinessGuideResponse
{
    public function __construct(
        #[OA\Property(description: 'Whether the Workshop shows the readiness guide.')]
        public bool $shown,

        #[OA\Property(description: 'When the guide was hidden. Null while it shows.', format: 'date-time', nullable: true)]
        public ?string $hiddenAt,
    ) {
    }
}
