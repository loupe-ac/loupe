<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Module\Insights\Command\BucketRuleRow;
use App\Module\Insights\Entity\InsightsBucketRule;
use OpenApi\Attributes as OA;

/** A time bucket rule. A tool call takes the bucket of the first rule whose pattern matches it. */
#[OA\Schema(required: ['id', 'position', 'pattern', 'bucket'])]
final readonly class BucketRuleResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(description: 'The place in the order the rules apply, counted from 0.', minimum: 0)]
        public int $position,

        #[OA\Property(description: 'A glob: `*` matches any run of characters and `?` matches one.', maxLength: InsightsBucketRule::MAX_PATTERN_LENGTH)]
        public string $pattern,

        #[OA\Property(description: 'The bucket name.', maxLength: InsightsBucketRule::MAX_BUCKET_LENGTH)]
        public string $bucket,
    ) {
    }

    public static function fromRow(BucketRuleRow $row): self
    {
        return new self((string) $row->rule->id, $row->place, $row->rule->pattern, $row->rule->bucket);
    }
}
