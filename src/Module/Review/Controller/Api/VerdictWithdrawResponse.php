<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['verdictId', 'documentStatus'])]
final readonly class VerdictWithdrawResponse
{
    public function __construct(
        #[OA\Property(description: 'The id of the withdrawal entry.', format: 'uuid')]
        public string $verdictId,

        #[OA\Property(enum: ['in-review', 'approved', 'changes-requested', 'draft'])]
        public string $documentStatus,
    ) {
    }
}
