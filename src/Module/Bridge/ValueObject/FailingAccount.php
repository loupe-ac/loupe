<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** An account of an online bridge that failed its check for the project of one open work request. */
final readonly class FailingAccount
{
    public function __construct(
        /** The label of the bridge. */
        public string $label,
        public string $account,
        /** The reason the bridge reported for the failed check. */
        public string $reason,
    ) {
    }
}
