<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Mercure\AccountArea;
use App\Module\Billing\Event\BillingStateChanged;
use App\Module\Billing\EventListener\PublishAccountChangedOnBillingStateChanged;
use App\Tests\Support\RecordedAccountSignals;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PublishAccountChangedOnBillingStateChangedTest extends TestCase
{
    public function test_it_signals_the_billing_area_on_the_account_topic_of_the_user(): void
    {
        $signals = new RecordedAccountSignals();
        $userId = Uuid::v7();

        new PublishAccountChangedOnBillingStateChanged($signals->publisher)(new BillingStateChanged($userId));

        $signals->assertNothingSentYet();
        $signals->assertSignalled($userId, AccountArea::BILLING);
    }
}
