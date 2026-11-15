<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Mercure\AccountArea;
use App\Mercure\AccountChangedPublisher;
use App\Module\Billing\Event\BillingStateChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Tells the open billing pages of a user that their billing state changed. */
#[AsEventListener]
final readonly class PublishAccountChangedOnBillingStateChanged
{
    public function __construct(
        private AccountChangedPublisher $publisher,
    ) {
    }

    public function __invoke(BillingStateChanged $event): void
    {
        $this->publisher->changed($event->userId, AccountArea::BILLING);
    }
}
