<?php

declare(strict_types=1);

namespace App\Module\Billing\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Dispatched after the billing state of a user changed and committed: a
 * subscription, a trial, a comp or the disabled mark that follows from them.
 */
final readonly class BillingStateChanged
{
    public function __construct(
        public Uuid $userId,
    ) {
    }
}
