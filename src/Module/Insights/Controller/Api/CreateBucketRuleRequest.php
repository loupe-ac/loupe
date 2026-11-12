<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Module\Insights\Entity\InsightsBucketRule;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new bucket rule. The handler checks the pattern and the bucket name, so a refusal reaches
 * the client as one list. A missing field and an explicit null both fail NotNull, so the type
 * stays nullable and the schema does not.
 */
#[OA\Schema(required: ['pattern', 'bucket'])]
final class CreateBucketRuleRequest
{
    #[Assert\NotNull]
    #[OA\Property(description: 'A glob: `*` matches any run of characters and `?` matches one, such as `LSP` or `mcp__loupe__*`. The rule goes last in the order the rules apply.', nullable: false, maxLength: InsightsBucketRule::MAX_PATTERN_LENGTH)]
    public ?string $pattern = null;

    #[Assert\NotNull]
    #[OA\Property(description: 'The bucket name, 1 to 64 characters: lower case letters, digits, underscore and hyphen.', nullable: false, maxLength: InsightsBucketRule::MAX_BUCKET_LENGTH, minLength: 1)]
    public ?string $bucket = null;
}
