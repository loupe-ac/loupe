<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Messenger;

use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Messenger\ReconcileCardWaitsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ReconcileCardWaitsHandlerTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private ReconcileCardWaitsHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $handler = self::getContainer()->get(ReconcileCardWaitsHandler::class);
        self::assertInstanceOf(ReconcileCardWaitsHandler::class, $handler);
        $this->handler = $handler;
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_it_reconciles_the_cards_of_the_project(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'reconcile-message'), 'reconcile-message');
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $document->addVersion('# One', '<h1>One</h1>');
        $this->em->flush();
        $this->storeInbox($this->em, $card, $this->inboxEntry('document-in-review', new MarkerSubject(MarkerSubjectType::Document, ['document' => (string) $document->id, 'version' => '1'])));

        ($this->handler)(new ReconcileCardWaits((string) $project->id, [(string) $card->id]));

        self::assertSame(1, $this->openWaitItems((string) $project->id));
    }

    public function test_inside_an_unflushed_write_it_queues_the_message_and_writes_nothing(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'reconcile-inline'), 'reconcile-inline');
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $document->addVersion('# One', '<h1>One</h1>');
        $this->em->flush();
        $this->storeInbox($this->em, $card, $this->inboxEntry('document-in-review', new MarkerSubject(MarkerSubjectType::Document, ['document' => (string) $document->id, 'version' => '1'])));
        $pending = new Document($project->owner, $project, 'Not flushed yet');
        $this->em->persist($pending);
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $message = new ReconcileCardWaits((string) $project->id, [(string) $card->id]);

        ($this->handler)($message);

        self::assertSame(0, $this->openWaitItems((string) $project->id));
        self::assertSame([$message], array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()));
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($pending));
    }

    public function test_a_requeue_keeps_the_queue_of_the_message(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'reconcile-requeue'), 'reconcile-requeue');
        $card = $this->card($this->em, $project);
        $this->em->flush();
        $this->em->persist(new Document($project->owner, $project, 'Not flushed yet'));
        $interactive = self::getContainer()->get('messenger.transport.interactive');
        $jobs = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $interactive);
        self::assertInstanceOf(InMemoryTransport::class, $jobs);
        $interactive->reset();
        $jobs->reset();
        $fast = new ReconcileCardWaits((string) $project->id, [(string) $card->id], personWaiting: true);

        ($this->handler)($fast);

        self::assertSame([$fast], array_map(static fn ($envelope) => $envelope->getMessage(), $interactive->getSent()));
        self::assertSame([], $jobs->getSent());
    }

    public function test_a_deleted_project_is_skipped(): void
    {
        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items');

        ($this->handler)(new ReconcileCardWaits((string) Uuid::v7(), null));

        self::assertSame($before, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items'));
    }

    private function openWaitItems(string $projectId): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM inbox_items WHERE project_id = :id AND origin = 'loupe' AND state = 'open'", ['id' => $projectId]);
    }
}
