<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** A missing `position` and an explicit null both fail NotNull, so the type stays nullable and the schema does not. */
#[OA\Schema(required: ['position'])]
final class UpdateBucketRuleRequest
{
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[OA\Property(description: 'The place to take in the order the rules apply, counted from 0 as the rule list counts it. A place past the last puts the rule last. The other rules keep their order.', nullable: false, minimum: 0)]
    public ?int $position = null;
}
