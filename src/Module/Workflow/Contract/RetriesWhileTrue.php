<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/**
 * An action whose done request counts as a failed try while its rule still holds. The rule then asks again
 * with the retries of the onWorkFailed block, because a request that ended changed nothing the rule reads.
 */
interface RetriesWhileTrue
{
    /** @param array<string, int|string> $params */
    public static function retriesWhileTrue(array $params): bool;
}
