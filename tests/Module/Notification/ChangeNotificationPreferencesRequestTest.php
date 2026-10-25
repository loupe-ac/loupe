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
    public function test_a_cell_that_the_browser_left_out_reads_as_off(): void
    {
        $request = new ChangeNotificationPreferencesRequest(['other-inbox-item__web-push' => true]);

        $choices = $request->toChoices();

        self::assertCount(2, $choices);
        self::assertSame(NotificationEvent::BlockingInboxItem, $choices[0]->event);
        self::assertFalse($choices[0]->enabled);
        self::assertSame(NotificationEvent::OtherInboxItem, $choices[1]->event);
        self::assertTrue($choices[1]->enabled);
    }

    public function test_the_cells_round_trip_through_the_request(): void
    {
        $cells = [
            new NotificationChoice(NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, true),
            new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, false),
        ];

        $choices = ChangeNotificationPreferencesRequest::fromCells($cells)->toChoices();

        self::assertEquals($cells, $choices);
    }
}
