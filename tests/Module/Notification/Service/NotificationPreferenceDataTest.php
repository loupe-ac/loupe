<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Repository\NotificationPreferenceRepository;
use App\Module\Notification\Service\NotificationPreferenceAccountPurger;
use App\Module\Notification\Service\NotificationPreferenceExporter;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\Notification\NotificationScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationPreferenceDataTest extends KernelTestCase
{
    use NotificationScenario;

    public function test_the_purger_deletes_the_rows_of_the_user_and_no_other(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'pref-purge-user@example.com');
        $other = $this->makeUser($container, 'pref-purge-other@example.com');
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($user, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false));
        $em->persist(new NotificationPreference($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $kept = new NotificationPreference($other, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true);
        $em->persist($kept);
        $em->flush();
        $keptId = $kept->id;

        $container->get(NotificationPreferenceAccountPurger::class)->purge($user, new AccountDeletionCleanup());

        $em->clear();
        $repository = $container->get(NotificationPreferenceRepository::class);
        self::assertSame(0, $repository->count(['owner' => $user->id]));
        self::assertNotNull($em->find(NotificationPreference::class, $keptId));
    }

    public function test_the_purger_runs_after_the_project_purger(): void
    {
        $container = static::getContainer();

        self::assertGreaterThan(
            $container->get(ProjectAccountPurger::class)->deletionOrder(),
            $container->get(NotificationPreferenceAccountPurger::class)->deletionOrder(),
        );
    }

    public function test_deleting_the_user_removes_the_rows_through_the_foreign_key(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'pref-cascade@example.com');
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $em->flush();

        $em->remove($user);
        $em->flush();

        self::assertSame(0, $container->get(NotificationPreferenceRepository::class)->count([]));
    }

    public function test_the_exporter_holds_the_choices_of_the_user_alone(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'pref-export-user@example.com');
        $other = $this->makeUser($container, 'pref-export-other@example.com');
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $em->persist(new NotificationPreference($other, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false));
        $em->flush();

        $exporter = $container->get(NotificationPreferenceExporter::class);
        $rows = iterator_to_array($exporter->export($user), false);

        self::assertSame('notification_preferences.json', $exporter->filename());
        self::assertSame([['event' => 'other-inbox-item', 'channel' => 'web-push', 'enabled' => true]], $rows);
    }
}
