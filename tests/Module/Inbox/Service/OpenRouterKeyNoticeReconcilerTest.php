<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxOpenRouterKeyNotice;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxOpenRouterKeyNoticeRepository;
use App\Module\Inbox\Service\OpenRouterKeyNoticeReconciler;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Inbox\QueuedPushes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class OpenRouterKeyNoticeReconcilerTest extends KernelTestCase
{
    use InboxFixtures;
    use QueuedPushes;

    private const string NOW = '2026-10-31 12:00:00';

    private EntityManagerInterface $em;
    private MockClock $clock;
    private OpenRouterKeyNoticeReconciler $reconciler;
    private Project $project;
    private Uuid $keyId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reconciler = self::getContainer()->get(OpenRouterKeyNoticeReconciler::class);
        self::assertInstanceOf(OpenRouterKeyNoticeReconciler::class, $reconciler);
        $this->reconciler = $reconciler;

        $this->project = $this->project($em, $this->owner($em, 'key-notice'), 'key-notice');
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->keyId = $this->openRouterKey($em);
    }

    public function test_a_refused_account_key_opens_one_notice_in_the_project(): void
    {
        $this->jobs()->reset();

        $this->reconciler->refused($this->keyId, false, $this->project->requireId());

        $notice = $this->onlyNotice();
        $item = $notice->item;
        self::assertSame(InboxItemKind::Notice, $item->kind);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertFalse($item->blocking);
        self::assertSame(InboxAskOrigin::Loupe, $item->origin);
        self::assertSame('OpenRouter refused the key', $item->title);
        self::assertStringContainsString('OpenRouter refused the account key.', $this->bodyOf($notice));
        self::assertStringContainsString('Your account', $this->bodyOf($notice));
        self::assertSame($this->project->id?->toRfc4122(), $notice->project->id?->toRfc4122());
        self::assertEquals($this->keyId, $notice->keyId);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $notice->startedAt);
        self::assertNull($notice->endedAt);
        self::assertSame(1, $this->searchHits('openrouter'));
        self::assertEquals([new NotifyInboxOwner((string) $item->id, NotifyInboxOwnerKind::Notice)], $this->queuedPushes());
    }

    public function test_a_refused_project_key_says_it_is_the_project_key(): void
    {
        $this->reconciler->refused($this->keyId, true, $this->project->requireId());

        $body = $this->bodyOf($this->onlyNotice());
        self::assertStringContainsString('OpenRouter refused the key of this project.', $body);
        self::assertStringContainsString('project settings', $body);
    }

    public function test_a_second_refusal_of_the_same_key_in_the_same_project_changes_nothing(): void
    {
        $this->jobs()->reset();

        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $this->reconciler->refused($this->keyId, false, $this->project->requireId());

        self::assertCount(1, $this->notices());
        self::assertSame(1, $this->itemCount());
        self::assertCount(1, $this->queuedPushes());
    }

    public function test_the_same_key_refused_in_two_projects_opens_a_notice_in_each(): void
    {
        $second = $this->project($this->em, $this->project->owner, 'key-notice-second');
        $this->em->flush();

        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $this->reconciler->refused($this->keyId, false, $second->requireId());

        $notices = $this->notices();
        self::assertCount(2, $notices);
        self::assertEqualsCanonicalizing(
            [$this->project->id?->toRfc4122(), $second->id?->toRfc4122()],
            array_map(static fn (InboxOpenRouterKeyNotice $notice): ?string => $notice->project->id?->toRfc4122(), $notices),
        );
    }

    public function test_a_different_key_refused_in_the_same_project_opens_another_notice(): void
    {
        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $this->reconciler->refused($this->openRouterKey($this->em), true, $this->project->requireId());

        self::assertCount(2, $this->notices());
    }

    public function test_the_inbox_off_opens_no_notice(): void
    {
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        $this->reconciler->refused($this->keyId, false, $this->project->requireId());

        self::assertSame([], $this->notices());
        self::assertSame(0, $this->itemCount());
    }

    public function test_a_refusal_for_a_deleted_project_opens_nothing(): void
    {
        $this->reconciler->refused($this->keyId, false, Uuid::v7());

        self::assertSame([], $this->notices());
    }

    public function test_a_key_that_works_closes_every_open_notice_of_it_as_done_with_a_note(): void
    {
        $second = $this->project($this->em, $this->project->owner, 'key-notice-works');
        $this->em->flush();
        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $this->reconciler->refused($this->keyId, false, $second->requireId());
        $this->reconciler->refused($this->openRouterKey($this->em), false, $this->project->requireId());
        $this->clock->sleep(600);

        $this->reconciler->works($this->keyId);

        $closed = array_values(array_filter($this->notices(), static fn (InboxOpenRouterKeyNotice $notice): bool => null !== $notice->endedAt));
        self::assertCount(2, $closed);
        foreach ($closed as $notice) {
            self::assertSame(InboxItemState::Done, $notice->item->state);
            self::assertSame('OpenRouter accepts the key again since '.self::stamp('2026-10-31 12:10:00').'. It was refused from '.self::stamp(self::NOW).'.', $notice->item->closeNote);
            self::assertEquals(new \DateTimeImmutable('2026-10-31 12:10:00'), $notice->endedAt);
        }
        self::assertCount(1, $this->openNotices(), 'the notice of another key stays open');
    }

    public function test_a_second_works_signal_changes_nothing(): void
    {
        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $this->reconciler->works($this->keyId);
        $this->clock->sleep(60);

        $this->reconciler->works($this->keyId);

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->onlyNotice()->endedAt);
    }

    public function test_a_removed_key_closes_its_notices_as_obsolete_with_no_note(): void
    {
        $this->reconciler->refused($this->keyId, true, $this->project->requireId());

        $this->reconciler->removed($this->keyId);

        $notice = $this->onlyNotice();
        self::assertSame(InboxItemState::Obsolete, $notice->item->state);
        self::assertNull($notice->item->closeNote);
        self::assertNotNull($notice->endedAt);
    }

    public function test_a_later_refusal_opens_a_new_notice_after_the_first_closed(): void
    {
        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $this->reconciler->works($this->keyId);

        $this->reconciler->refused($this->keyId, false, $this->project->requireId());

        self::assertCount(2, $this->notices());
        self::assertCount(1, $this->openNotices());
    }

    public function test_a_notice_whose_item_closed_elsewhere_ends_and_a_new_one_opens(): void
    {
        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $first = $this->onlyNotice();
        $first->item->state = InboxItemState::Obsolete;
        $this->em->flush();

        $this->reconciler->refused($this->keyId, false, $this->project->requireId());

        self::assertNotNull($first->endedAt);
        self::assertCount(1, $this->openNotices());
        self::assertNotSame($first, $this->openNotices()[0]);
    }

    public function test_a_refusal_of_a_removed_key_opens_nothing(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM openrouter_keys WHERE id = :id', ['id' => $this->keyId->toRfc4122()]);

        $this->reconciler->refused($this->keyId, false, $this->project->requireId());

        self::assertSame([], $this->notices());
        self::assertSame(0, $this->itemCount());
    }

    public function test_a_close_reads_the_item_and_the_notice_again_under_the_lock(): void
    {
        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $notice = $this->onlyNotice();
        self::assertSame(InboxItemState::Open, $notice->item->state);
        $this->em->getConnection()->executeStatement("UPDATE inbox_items SET state = 'obsolete' WHERE id = :id", ['id' => (string) $notice->item->id]);

        $this->reconciler->works($this->keyId);

        self::assertSame(InboxItemState::Obsolete, $notice->item->state);
        self::assertNull($notice->item->closeNote);
        self::assertSame('obsolete', $this->em->getConnection()->fetchOne('SELECT state FROM inbox_items WHERE id = :id', ['id' => (string) $notice->item->id]));
        self::assertNotNull($notice->endedAt);
    }

    public function test_the_reload_reads_the_end_another_process_stored(): void
    {
        $this->reconciler->refused($this->keyId, false, $this->project->requireId());
        $notice = $this->onlyNotice();
        $this->em->getConnection()->executeStatement("UPDATE inbox_openrouter_key_notices SET ended_at = '2026-10-31 11:00:00' WHERE id = :id", ['id' => (string) $notice->id]);

        $this->repository()->reloadEndedAt($notice);
        $this->em->flush();

        self::assertEquals(new \DateTimeImmutable('2026-10-31 11:00:00'), $notice->endedAt);
        self::assertSame('2026-10-31 11:00:00', $this->em->getConnection()->fetchOne('SELECT ended_at FROM inbox_openrouter_key_notices WHERE id = :id', ['id' => (string) $notice->id]));
    }

    private function bodyOf(InboxOpenRouterKeyNotice $notice): string
    {
        return $notice->item->body ?? throw new \LogicException('A notice has a body.');
    }

    /** @return list<InboxOpenRouterKeyNotice> */
    private function notices(): array
    {
        return array_values($this->repository()->findBy([], ['startedAt' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<InboxOpenRouterKeyNotice> */
    private function openNotices(): array
    {
        return array_values(array_filter($this->notices(), static fn (InboxOpenRouterKeyNotice $notice): bool => null === $notice->endedAt));
    }

    private function onlyNotice(): InboxOpenRouterKeyNotice
    {
        $notices = $this->notices();
        self::assertCount(1, $notices);

        return $notices[0];
    }

    private function itemCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE project_id IN (SELECT id FROM projects WHERE owner_id = :owner)', ['owner' => (string) $this->project->owner->id]);
    }

    private function searchHits(string $query): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM inbox_items WHERE project_id = :project AND search_vector @@ websearch_to_tsquery('english', :query)",
            ['project' => (string) $this->project->id, 'query' => $query],
        );
    }

    private static function stamp(string $moment): string
    {
        return new \DateTimeImmutable($moment)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
    }

    private function repository(): InboxOpenRouterKeyNoticeRepository
    {
        $repository = self::getContainer()->get(InboxOpenRouterKeyNoticeRepository::class);
        self::assertInstanceOf(InboxOpenRouterKeyNoticeRepository::class, $repository);

        return $repository;
    }
}
