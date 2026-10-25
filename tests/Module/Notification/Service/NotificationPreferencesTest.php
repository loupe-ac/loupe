<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Service\NotificationPreferences;
use App\Tests\Module\Notification\NotificationScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationPreferencesTest extends KernelTestCase
{
    use NotificationScenario;

    public function test_a_user_with_no_row_gets_the_default_of_each_event(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'prefs-default@example.com');
        $preferences = $container->get(NotificationPreferences::class);

        self::assertTrue($preferences->isEnabled($user, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush));
        self::assertFalse($preferences->isEnabled($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush));
    }

    public function test_a_stored_row_overrides_the_default_for_that_user_alone(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'prefs-override@example.com');
        $other = $this->makeUser($container, 'prefs-other@example.com');
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($user, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false));
        $em->persist(new NotificationPreference($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $em->flush();
        $preferences = $container->get(NotificationPreferences::class);

        self::assertFalse($preferences->isEnabled($user, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush));
        self::assertTrue($preferences->isEnabled($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush));
        self::assertTrue($preferences->isEnabled($other, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush));
        self::assertFalse($preferences->isEnabled($other, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush));
    }

    public function test_the_grid_holds_one_cell_for_every_event_and_channel(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'prefs-grid@example.com');
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $em->flush();

        $cells = $container->get(NotificationPreferences::class)->grid($user);

        self::assertCount(\count(NotificationEvent::cases()) * \count(NotificationChannel::cases()), $cells);
        self::assertSame(
            [
                'blocking-inbox-item__web-push' => true,
                'other-inbox-item__web-push' => true,
            ],
            array_column(array_map(static fn ($cell): array => [$cell->event->value.'__'.$cell->channel->value, $cell->enabled], $cells), 1, 0),
        );
    }
}
