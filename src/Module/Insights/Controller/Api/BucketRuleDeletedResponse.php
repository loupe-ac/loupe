<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of a bucket rule delete. */
#[OA\Schema(required: ['deleted'])]
final readonly class BucketRuleDeletedResponse
{
    public function __construct(
        #[OA\Property(description: 'The id of the deleted rule.', format: 'uuid')]
        public string $deleted,
    ) {
    }
}
