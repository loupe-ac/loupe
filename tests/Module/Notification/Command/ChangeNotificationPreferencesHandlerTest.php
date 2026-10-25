<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Command;

use App\Module\Notification\Command\ChangeNotificationPreferencesCommand;
use App\Module\Notification\Command\ChangeNotificationPreferencesHandler;
use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationChoice;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Repository\NotificationPreferenceRepository;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;

final class ChangeNotificationPreferencesHandlerTest extends KernelTestCase
{
    use NotificationScenario;

    public function test_a_choice_that_equals_the_default_stores_no_row(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'change-default@example.com');
        $audit = $this->audit();

        $this->handler($audit)(new ChangeNotificationPreferencesCommand($user, [
            new NotificationChoice(NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, true),
            new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, false),
        ]));

        self::assertSame(0, $container->get(NotificationPreferenceRepository::class)->count([]));
        self::assertSame([], $audit->operations());
    }

    public function test_a_choice_that_differs_from_the_default_stores_one_row_and_records_it(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'change-store@example.com');
        $audit = $this->audit();

        $this->handler($audit)(new ChangeNotificationPreferencesCommand($user, [
            new NotificationChoice(NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false),
            new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true),
        ]));

        $rows = $container->get(NotificationPreferenceRepository::class)->findByOwner($user);
        self::assertCount(2, $rows);
        self::assertSame(
            ['blocking-inbox-item' => false, 'other-inbox-item' => true],
            array_column(array_map(static fn (NotificationPreference $row): array => [$row->event->value, $row->enabled], $rows), 1, 0),
        );
        $record = $audit->record('notification.preferences_changed');
        self::assertSame((string) $user->id, $record->context['userId']);
        self::assertSame('blocking-inbox-item:web-push=off,other-inbox-item:web-push=on', $record->context['changes']);
    }

    public function test_sending_the_same_choices_again_writes_nothing_more(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'change-twice@example.com');
        $audit = $this->audit();
        $command = new ChangeNotificationPreferencesCommand($user, [
            new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true),
        ]);

        $this->handler($audit)($command);
        $this->handler($audit)($command);

        self::assertSame(1, $container->get(NotificationPreferenceRepository::class)->count([]));
        self::assertCount(1, $audit->records('notification.preferences_changed'));
    }

    public function test_flipping_a_choice_back_to_its_default_removes_the_row(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'change-back@example.com');
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($user, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false));
        $em->flush();
        $audit = $this->audit();

        $this->handler($audit)(new ChangeNotificationPreferencesCommand($user, [
            new NotificationChoice(NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, true),
        ]));

        self::assertSame(0, $container->get(NotificationPreferenceRepository::class)->count([]));
        self::assertCount(1, $audit->records('notification.preferences_changed'));
    }

    public function test_a_stored_row_flips_in_place(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'change-flip@example.com');
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $em->flush();

        $this->handler($this->audit())(new ChangeNotificationPreferencesCommand($user, [
            new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, false),
        ]));

        self::assertSame(0, $container->get(NotificationPreferenceRepository::class)->count([]));
    }

    public function test_the_choices_of_one_user_never_reach_another(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'change-mine@example.com');
        $other = $this->makeUser($container, 'change-theirs@example.com');

        $this->handler($this->audit())(new ChangeNotificationPreferencesCommand($user, [
            new NotificationChoice(NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true),
        ]));

        self::assertSame(1, $container->get(NotificationPreferenceRepository::class)->count(['owner' => $user->id]));
        self::assertSame(0, $container->get(NotificationPreferenceRepository::class)->count(['owner' => $other->id]));
    }

    private function audit(): RecordingAuditor
    {
        return new RecordingAuditor(static::getContainer()->get(AuditActorProviderInterface::class));
    }

    private function handler(RecordingAuditor $audit): ChangeNotificationPreferencesHandler
    {
        $container = static::getContainer();

        return new ChangeNotificationPreferencesHandler(
            $container->get(NotificationPreferenceRepository::class),
            $container->get(EntityManagerInterface::class),
            $audit->auditor,
        );
    }
}
