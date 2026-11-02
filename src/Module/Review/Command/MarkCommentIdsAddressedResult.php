<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class MarkCommentIdsAddressedResult
{
    /**
     * @param list<string>                            $addressed ids that moved to addressed, in the order given
     * @param list<array{id: string, reason: string}> $skipped   ids that did not move, in the order given
     */
    public function __construct(
        public array $addressed,
        public array $skipped,
    ) {
    }
}
