<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Diagnostics;

use App\Module\Account\Diagnostics\WaitlistNotificationRecipientsCheck;
use App\Module\Account\Service\WaitlistNotificationRecipients;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class WaitlistNotificationRecipientsCheckTest extends TestCase
{
    public function test_an_empty_list_is_ok_and_says_the_emails_are_off(): void
    {
        $diagnostic = $this->check('')();

        self::assertSame('waitlist_notification_recipients', $diagnostic->key);
        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('account.system_status.waitlist_notification_recipients.off', $diagnostic->detail);
    }

    public function test_a_list_of_valid_addresses_is_ok_and_counts_them(): void
    {
        $diagnostic = $this->check('ana@example.com, bo@example.org')();

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('account.system_status.waitlist_notification_recipients.ok', $diagnostic->detail);
        self::assertSame(['%count%' => 2], $diagnostic->detailParameters);
    }

    public function test_bad_entries_give_one_warning_that_names_every_one(): void
    {
        $diagnostic = $this->check('ana@example.com, bo@example, carl')();

        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
        self::assertSame('account.system_status.waitlist_notification_recipients.invalid', $diagnostic->detail);
        self::assertSame(['%count%' => 2, '%entries%' => 'bo@example, carl'], $diagnostic->detailParameters);
    }

    public function test_a_list_with_only_bad_entries_is_a_warning(): void
    {
        $diagnostic = $this->check('carl')();

        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
        self::assertSame(['%count%' => 1, '%entries%' => 'carl'], $diagnostic->detailParameters);
    }

    private function check(string $value): WaitlistNotificationRecipientsCheck
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturn($value);

        return new WaitlistNotificationRecipientsCheck(new WaitlistNotificationRecipients($flags));
    }
}
