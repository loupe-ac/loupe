<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification;

use App\Module\Notification\Form\ChangeNotificationPreferencesRequest;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationChoice;
use App\Module\Notification\NotificationEvent;
use PHPUnit\Framework\TestCase;

final class ChangeNotificationPreferencesRequestTest extends TestCase
{
    private const array SHOWN = ['blocking-inbox-item__web-push' => '1', 'other-inbox-item__web-push' => '0'];

    public function test_a_cell_that_the_browser_left_out_reads_as_off(): void
    {
        $request = new ChangeNotificationPreferencesRequest(['other-inbox-item__web-push' => true], self::SHOWN);

        $choices = $request->toChoices();

        self::assertEquals([
            new NotificationChoice(NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false),
            new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true),
        ], $choices);
    }

    public function test_a_cell_that_matches_what_the_page_showed_is_not_a_change(): void
    {
        $request = new ChangeNotificationPreferencesRequest(['blocking-inbox-item__web-push' => true, 'other-inbox-item__web-push' => true], self::SHOWN);

        self::assertEquals(
            [new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true)],
            $request->toChoices(),
        );
    }

    public function test_a_cell_the_page_did_not_show_is_left_alone(): void
    {
        $request = new ChangeNotificationPreferencesRequest([], ['other-inbox-item__web-push' => '0', 'blocking-inbox-item__web-push' => 'yes']);

        self::assertSame([], $request->toChoices());
    }

    public function test_the_cells_round_trip_through_the_request_as_no_change(): void
    {
        $cells = [
            new NotificationChoice(NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, true),
            new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, false),
        ];

        $request = ChangeNotificationPreferencesRequest::fromCells($cells);

        self::assertSame(self::SHOWN, $request->shown);
        self::assertSame([], $request->toChoices());
    }
}
