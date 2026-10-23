<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxCardWatchTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_a_watch_and_its_waits_round_trip_in_start_order(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-round-trip'), 'inbox');
        $cardId = Uuid::v7();
        $documentId = Uuid::v7();
        $watch = $this->watch($project, $cardId, 7);
        $watch->waits->add(new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7(), startedAt: new \DateTimeImmutable('2026-09-28 10:05:00')));
        $watch->waits->add(new InboxCardWait($watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: $documentId, versionNumber: 2, startedAt: new \DateTimeImmutable('2026-09-28 10:00:00')));
        $this->em->flush();
        $watchId = $watch->id;
        $this->em->clear();

        $stored = $this->em->find(InboxCardWatch::class, $watchId);
        self::assertInstanceOf(InboxCardWatch::class, $stored);
        self::assertEquals($cardId, $stored->cardId);
        self::assertSame(7, $stored->cardNumber);
        self::assertSame(InboxItemKind::Wait, $stored->item->kind);
        self::assertSame(['document-in-review', 'run-needs-you'], array_map(static fn (InboxCardWait $wait): string => $wait->markerKey, array_values($stored->waits->toArray())));
        $document = $stored->waits->first();
        self::assertInstanceOf(InboxCardWait::class, $document);
        self::assertEquals($documentId, $document->documentId);
        self::assertSame(2, $document->versionNumber);
        self::assertNull($document->runId);
        self::assertNull($document->endedAt);
        self::assertNull($document->endReason);
    }

    public function test_an_ended_wait_keeps_its_end_reason(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-end'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $wait = new InboxCardWait($watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: Uuid::v7(), versionNumber: 1);
        $watch->waits->add($wait);
        $wait->endedAt = new \DateTimeImmutable();
        $wait->endReason = InboxCardWaitEndReason::CardFinished;
        $this->em->flush();
        $waitId = $wait->id;
        $this->em->clear();

        self::assertSame(InboxCardWaitEndReason::CardFinished, $this->em->find(InboxCardWait::class, $waitId)?->endReason);
    }

    public function test_a_card_holds_one_open_watch(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-open-twice'), 'inbox');
        $cardId = Uuid::v7();
        $this->watch($project, $cardId, number: 1);
        $this->watch($project, $cardId, number: 2);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_a_card_opens_a_new_watch_once_its_watch_is_closed(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-reopen'), 'inbox');
        $cardId = Uuid::v7();
        $closed = $this->watch($project, $cardId, number: 1);
        $closed->closedAt = new \DateTimeImmutable();
        $closed->dismissedAt = new \DateTimeImmutable();
        $this->watch($project, $cardId, number: 2);
        $this->em->flush();

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_card_watches WHERE card_id = :id', ['id' => (string) $cardId]));
    }

    public function test_deleting_the_item_removes_its_watch_and_waits(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-cascade'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $watch->waits->add(new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7()));
        $this->em->flush();
        $watchId = (string) $watch->id;

        $this->connection->executeStatement('DELETE FROM inbox_items WHERE id = :id', ['id' => (string) $watch->item->id]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_card_watches WHERE id = :id', ['id' => $watchId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_card_waits WHERE watch_id = :id', ['id' => $watchId]));
    }

    public function test_a_watch_belongs_to_a_wait_item(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-question'), 'inbox');

        $this->expectException(\InvalidArgumentException::class);
        new InboxCardWatch($this->item($this->em, $project), Uuid::v7(), 1);
    }

    public function test_the_key_names_the_document_version_or_the_run(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-key'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $documentId = Uuid::fromString('01a0e5cf-c001-7967-9205-b049b12f0957');
        $runId = Uuid::fromString('01a0e91e-8210-768c-ae61-cba18491a4fc');

        $document = new InboxCardWait($watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: $documentId, versionNumber: 3);
        $run = new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $runId);

        self::assertSame('document-in-review:01a0e5cf-c001-7967-9205-b049b12f0957:3', $document->key());
        self::assertSame('run-needs-you:01a0e91e-8210-768c-ae61-cba18491a4fc', $run->key());
        self::assertSame($document->key(), InboxCardWait::computeKey('document-in-review', InboxCardWaitType::Document, ['document' => (string) $documentId, 'version' => '3']));
        self::assertNotSame($document->key(), InboxCardWait::computeKey('document-in-review', InboxCardWaitType::Document, ['document' => (string) $documentId, 'version' => '4']));
        self::assertNotSame($run->key(), InboxCardWait::computeKey('paused', InboxCardWaitType::WorkerRun, ['run' => (string) $runId]));
    }

    public function test_the_key_of_a_pull_request_wait_names_the_pull_request_and_its_head_commit(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-pull-request-key'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $pullRequestId = Uuid::fromString('01a0f3c2-5d10-7b5e-9c1a-2f4e8d6b0a11');
        $headSha = str_repeat('a1', 20);

        $wait = new InboxCardWait($watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: $pullRequestId, headSha: $headSha);

        self::assertSame('waits-for-approval:01a0f3c2-5d10-7b5e-9c1a-2f4e8d6b0a11:'.$headSha, $wait->key());
        self::assertNotSame($wait->key(), InboxCardWait::computeKey('waits-for-approval', InboxCardWaitType::PullRequest, ['pullRequest' => (string) $pullRequestId, 'head' => str_repeat('b2', 20)]));
        self::assertNotSame($wait->key(), InboxCardWait::computeKey('checks-failed', InboxCardWaitType::PullRequest, ['pullRequest' => (string) $pullRequestId, 'head' => $headSha]));
    }

    public function test_a_pull_request_wait_round_trips(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-pull-request-round-trip'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $pullRequestId = Uuid::v7();
        $headSha = str_repeat('c3', 32);
        $wait = new InboxCardWait($watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: $pullRequestId, headSha: $headSha);
        $watch->waits->add($wait);
        $this->em->flush();
        $waitId = $wait->id;
        $this->em->clear();

        $stored = $this->em->find(InboxCardWait::class, $waitId);
        self::assertInstanceOf(InboxCardWait::class, $stored);
        self::assertSame('waits-for-approval', $stored->markerKey);
        self::assertSame('marker.waits_for_approval', $stored->label);
        self::assertSame([], $stored->params);
        self::assertEquals($pullRequestId, $stored->pullRequestId);
        self::assertSame($headSha, $stored->headSha);
        self::assertNull($stored->documentId);
        self::assertNull($stored->runId);
    }

    /** @param array<string, string> $ids */
    #[TestWith([InboxCardWaitType::Document, ['version' => '1']])]
    #[TestWith([InboxCardWaitType::Document, ['document' => 'd']])]
    #[TestWith([InboxCardWaitType::Document, ['document' => 'd', 'version' => '1', 'run' => 'r']])]
    #[TestWith([InboxCardWaitType::Document, ['document' => 'd', 'version' => '1', 'head' => 'abc123']])]
    #[TestWith([InboxCardWaitType::WorkerRun, []])]
    #[TestWith([InboxCardWaitType::WorkerRun, ['document' => 'd', 'version' => '1', 'run' => 'r']])]
    #[TestWith([InboxCardWaitType::WorkerRun, ['run' => 'r', 'pullRequest' => 'p']])]
    #[TestWith([InboxCardWaitType::WorkerRun, ['run' => 'r', 'pause' => 'p']])]
    #[TestWith([InboxCardWaitType::PullRequest, ['head' => 'abc123']])]
    #[TestWith([InboxCardWaitType::PullRequest, ['pullRequest' => 'p']])]
    #[TestWith([InboxCardWaitType::PullRequest, ['pullRequest' => 'p', 'head' => '']])]
    #[TestWith([InboxCardWaitType::PullRequest, ['run' => 'r', 'pullRequest' => 'p', 'head' => 'abc123']])]
    #[TestWith([InboxCardWaitType::CardPause, []])]
    #[TestWith([InboxCardWaitType::CardPause, ['pause' => 'p', 'run' => 'r']])]
    #[TestWith([InboxCardWaitType::Card, ['card' => 'c']])]
    #[TestWith([InboxCardWaitType::Card, ['spell' => 's']])]
    public function test_a_wait_carries_exactly_the_ids_of_its_type(InboxCardWaitType $type, array $ids): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InboxCardWait::computeKey('marker', $type, $ids);
    }

    public function test_a_card_wait_names_its_card_and_the_start_of_its_spell(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-card-wait'), 'inbox');
        $cardId = Uuid::v7();
        $watch = $this->watch($project, $cardId);
        $spell = new \DateTimeImmutable('2026-10-20 09:00:00', new \DateTimeZone('UTC'));
        $wait = InboxCardWait::forSubject($watch, 'nothing-happened', 'marker.nothing_happened', ['%days%' => 3], MarkerSubject::card($cardId, $spell), $spell);

        $key = \sprintf('nothing-happened:%s:2026-10-20T09:00:00+00:00', $cardId->toRfc4122());
        self::assertSame($key, $wait->key());
        self::assertSame(InboxCardWaitType::Card, $wait->type);
        self::assertEquals($spell, $wait->spellStartedAt);
    }

    public function test_a_card_wait_of_another_card_than_its_watch_is_refused(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-card-wait-other'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $spell = new \DateTimeImmutable('2026-10-20 09:00:00');

        $this->expectException(\InvalidArgumentException::class);
        InboxCardWait::forSubject($watch, 'nothing-happened', 'marker.nothing_happened', [], MarkerSubject::card(Uuid::v7(), $spell), $spell);
    }

    public function test_a_pause_wait_names_its_pause_and_round_trips(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'watch-pause'), 'inbox');
        $watch = $this->watch($project, Uuid::v7());
        $pauseId = Uuid::fromString('01a0f3c2-5d10-7b5e-9c1a-2f4e8d6b0a22');
        $wait = new InboxCardWait($watch, 'paused', InboxCardWaitType::CardPause, 'marker.paused', pauseId: $pauseId);
        $watch->waits->add($wait);
        $this->em->flush();
        $waitId = $wait->id;
        $this->em->clear();

        $stored = $this->em->find(InboxCardWait::class, $waitId);
        self::assertInstanceOf(InboxCardWait::class, $stored);
        self::assertSame('paused:01a0f3c2-5d10-7b5e-9c1a-2f4e8d6b0a22', $stored->key());
        self::assertEquals($pauseId, $stored->pauseId);
        self::assertNotSame($stored->key(), InboxCardWait::computeKey('paused', InboxCardWaitType::CardPause, ['pause' => (string) Uuid::v7()]));
    }

    private function watch(Project $project, Uuid $cardId, int $cardNumber = 1, int $number = 1): InboxCardWatch
    {
        $item = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Wait, title: '#'.$cardNumber.' Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->em->persist($item);
        $watch = new InboxCardWatch($item, $cardId, $cardNumber);
        $this->em->persist($watch);

        return $watch;
    }
}
