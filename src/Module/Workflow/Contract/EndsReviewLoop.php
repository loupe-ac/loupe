<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A condition whose `rounds` parameter is the number of failed reviews that ends a review loop. */
interface EndsReviewLoop
{
    /** @param array<string, mixed> $params */
    public function roundLimit(array $params): int;
}
