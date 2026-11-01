<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** An open work request that the server refused to one bridge. */
final readonly class RefusedRequest
{
    public function __construct(
        public Uuid $bridgeId,
        public Uuid $workRequestId,
        public string $subjectType,
        public Uuid $subjectId,
        public string $kind,
        /** The account the refused claim named, or null when it named none. */
        public ?string $account,
    ) {
    }
}
