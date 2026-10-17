<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Command\RecordRunAskHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\AskingBridge;
use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Inbox\Service\InboxCardTileRefresher;
use App\Module\Inbox\Service\InboxChangedPublisher;
use App\Module\Inbox\Service\InboxLinkResolver;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Inbox\Service\InboxSessionAsks;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class AskInboxHandlerTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
    }

    /**
     * A rival call from the same session commits its ask while this call waits
     * for the project lock. The lookup must run after the lock, or this call
     * misses the rival's ask and the one-open-ask index refuses its insert.
     */
    public function test_a_rival_ask_that_commits_while_the_call_waits_for_the_lock_is_extended(): void
    {
        $project = $this->persistedProject('ask-handler-rival-lock');
        $sessionId = Uuid::v4();
        $rivalAskId = Uuid::v7();

        $handler = $this->handler(new RivalAskOnLock($this->em, [
            'id' => (string) $rivalAskId,
            'project_id' => (string) $project->id,
            'session_id' => (string) $sessionId,
            'created_at' => '2026-09-14 00:00:00',
        ]));

        $view = $handler(new AskInboxCommand($project, $sessionId, [new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true)]));

        self::assertTrue($view->extended);
        self::assertSame((string) $rivalAskId, (string) $view->ask->id);
    }

    /** The project lock serialises one project. A session that asks in two at once meets the index instead. */
    public function test_a_rival_open_ask_in_another_project_is_refused_rather_than_fatal(): void
    {
        $project = $this->persistedProject('ask-handler-rival-here');
        $elsewhere = $this->persistedProject('ask-handler-rival-elsewhere');
        $sessionId = Uuid::v4();

        $handler = $this->handler(new RivalAskBeforeFlush($this->em, [
            'id' => (string) Uuid::v7(),
            'project_id' => (string) $elsewhere->id,
            'session_id' => (string) $sessionId,
            'created_at' => '2026-09-14 00:00:00',
        ]));

        try {
            $handler(new AskInboxCommand($project, $sessionId, [new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true)]));
            self::fail('Expected DomainErrors for a session whose open ask a concurrent call put in another project.');
        } catch (DomainErrors $e) {
            self::assertSame(['sessionId' => InboxSessionAsks::SESSION_ASK_ELSEWHERE], $e->errors);
        }
    }

    public function test_an_ask_is_recorded_without_the_text_the_agent_wrote(): void
    {
        $project = $this->persistedProject('ask-handler-audit');
        $sessionId = Uuid::v4();

        $view = $this->handler($this->em)(new AskInboxCommand($project, $sessionId, [
            new AskInboxItem(InboxItemKind::Question, 'A secret title', body: 'A secret body', freeText: true),
        ], context: 'A secret context'));

        $event = $this->audit->record('inbox.items_asked');
        self::assertSame(AuditOutcome::Success, $event->outcome);
        self::assertSame((string) $view->ask->id, $event->context['askId']);
        self::assertSame((string) $sessionId, $event->context['sessionId']);
        self::assertSame('1', $event->context['itemNumbers']);
        self::assertStringNotContainsString('secret', (string) json_encode($event->context));
    }

    public function test_a_blocking_question_about_the_card_of_the_run_is_recorded_on_the_run(): void
    {
        [$project, $card, $run, $sessionId, $bridgeId] = $this->runningWorker('ask-handler-link');

        $view = $this->handler($this->em)(new AskInboxCommand($project, $sessionId, [
            new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true, cardIds: [(string) $card->id]),
        ], $bridgeId));

        $this->em->clear();
        self::assertEquals($view->ask->id, $this->em->find(WorkerRun::class, $run->id)?->waitsOnAskId);
    }

    /** @return iterable<string, array{AskInboxItem, bool}> */
    public static function asksThatStandForNoStop(): iterable
    {
        yield 'a to-do that blocks' => [new AskInboxItem(InboxItemKind::Todo, 'Publish it', blocking: true, cardIds: ['CARD']), true];
        yield 'a question with no card' => [new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true), true];
        yield 'a question that does not block' => [new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true, blocking: false, cardIds: ['CARD']), true];
        yield 'a question about another card' => [new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true, cardIds: ['OTHER']), true];
        yield 'a question with no bridge id' => [new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true, cardIds: ['CARD']), false];
    }

    #[DataProvider('asksThatStandForNoStop')]
    public function test_an_ask_that_does_not_stand_for_the_stop_records_nothing(AskInboxItem $input, bool $withBridge): void
    {
        [$project, $card, $run, $sessionId, $bridgeId] = $this->runningWorker('ask-handler-no-link-'.md5((string) $this->dataName()));
        $other = $this->card($this->em, $project, 2);
        $this->em->flush();
        $item = new AskInboxItem($input->kind, $input->title, freeText: $input->freeText, blocking: $input->blocking, cardIds: array_map(static fn (string $id): string => match ($id) {
            'CARD' => (string) $card->id,
            default => (string) $other->id,
        }, $input->cardIds));

        $this->handler($this->em)(new AskInboxCommand($project, $sessionId, [$item], $withBridge ? $bridgeId : null));

        $this->em->clear();
        self::assertNull($this->em->find(WorkerRun::class, $run->id)?->waitsOnAskId);
    }

    public function test_an_ask_of_another_session_on_the_same_bridge_records_nothing(): void
    {
        [$project, $card, $run, , $bridgeId] = $this->runningWorker('ask-handler-other-session');

        $this->handler($this->em)(new AskInboxCommand($project, Uuid::v4(), [
            new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true, cardIds: [(string) $card->id]),
        ], $bridgeId));

        $this->em->clear();
        self::assertNull($this->em->find(WorkerRun::class, $run->id)?->waitsOnAskId);
    }

    public function test_a_run_that_has_stopped_records_no_ask(): void
    {
        [$project, $card, $run, $sessionId, $bridgeId] = $this->runningWorker('ask-handler-stopped');
        $run->state = WorkerRunState::Blocked;
        $this->em->flush();

        $this->handler($this->em)(new AskInboxCommand($project, $sessionId, [
            new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true, cardIds: [(string) $card->id]),
        ], $bridgeId));

        $this->em->clear();
        self::assertNull($this->em->find(WorkerRun::class, $run->id)?->waitsOnAskId);
    }

    /** @return array{Project, Card, WorkerRun, Uuid, Uuid} */
    private function runningWorker(string $slug): array
    {
        $project = $this->persistedProject($slug);
        $card = $this->card($this->em, $project, 1);
        $sessionId = Uuid::v4();
        $bridgeId = Uuid::v7();
        $run = new WorkerRun(
            project: $project,
            bridgeId: $bridgeId,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Running,
            sessionId: $sessionId,
            output: '',
        );
        $this->em->persist($run);
        $this->em->flush();

        return [$project, $card, $run, $sessionId, $bridgeId];
    }

    private function persistedProject(string $slug): Project
    {
        $project = $this->project($this->em, $this->owner($this->em, $slug), $slug);
        $this->em->flush();

        return $project;
    }

    private function handler(EntityManagerInterface $em): AskInboxHandler
    {
        $container = self::getContainer();
        $items = $container->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);
        $sessionAsks = $container->get(InboxSessionAsks::class);
        self::assertInstanceOf(InboxSessionAsks::class, $sessionAsks);
        $askingBridge = $container->get(AskingBridge::class);
        self::assertInstanceOf(AskingBridge::class, $askingBridge);
        $links = $container->get(InboxLinkResolver::class);
        self::assertInstanceOf(InboxLinkResolver::class, $links);
        $indexer = $container->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);

        $inboxChanged = $container->get(InboxChangedPublisher::class);
        self::assertInstanceOf(InboxChangedPublisher::class, $inboxChanged);
        $cardWaits = $container->get(CardWaitTrigger::class);
        self::assertInstanceOf(CardWaitTrigger::class, $cardWaits);

        $workerRuns = $container->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $workerRuns);

        return new AskInboxHandler($items, $sessionAsks, $askingBridge, $links, $indexer, $em, $this->audit->auditor, $inboxChanged, $cardWaits, new InboxCardTileRefresher(new EventDispatcher()), $workerRuns, new RecordRunAskHandler());
    }
}
