<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030141507;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../migrations/Version20261030141507.php';

final class InboxCloseRecordMigrationTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;

    private Connection $connection;

    private Project $project;

    private Card $card;

    private int $number = 0;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->project = $this->project($em, $this->owner($em, 'close-record'), 'close-record');
        $this->card = $this->card($em, $this->project);
        $em->flush();
    }

    public function test_every_stored_row_gets_its_best_known_closer_cause_and_note(): void
    {
        $answered = $this->item(InboxItemKind::Question);
        $answeredByAgent = $this->item(InboxItemKind::Question);
        $declined = $this->item(InboxItemKind::Todo);
        $doneByHand = $this->item(InboxItemKind::Todo);
        $doneByCommand = $this->item(InboxItemKind::Todo);
        $withdrawn = $this->item(InboxItemKind::Question);
        $workflowWithdrawn = $this->item(InboxItemKind::Workflow, InboxAskOrigin::Loupe);
        $workflowAnswered = $this->item(InboxItemKind::Workflow, InboxAskOrigin::Loupe);
        $obsolete = $this->item(InboxItemKind::Todo);
        $waitDone = $this->item(InboxItemKind::Wait, InboxAskOrigin::Loupe);
        $noticeKey = $this->item(InboxItemKind::Notice, InboxAskOrigin::Loupe);
        $noticeSentence = $this->item(InboxItemKind::Notice, InboxAskOrigin::Loupe);
        $noticeObsolete = $this->item(InboxItemKind::Notice, InboxAskOrigin::Loupe);
        $reviewByPerson = $this->item(InboxItemKind::Review);
        $reviewByRule = $this->item(InboxItemKind::Review);
        $automaticReviewEnded = $this->item(InboxItemKind::Review, InboxAskOrigin::Loupe);
        $open = $this->item(InboxItemKind::Question);

        $reviewRowByPerson = $this->review($reviewByPerson, null);
        $reviewRowByRule = $this->review($reviewByRule, 'tech-design-auto-approve');
        $this->review($automaticReviewEnded, null, withVerdict: false);
        $this->commandAction($doneByCommand);
        $this->em->flush();
        $reviewRowIds = [(string) $reviewRowByPerson->id, (string) $reviewRowByRule->id];

        $this->migrate('down');
        $this->storeOld($answered, 'answered', answerText: 'Both');
        $this->storeOld($answeredByAgent, 'answered', answerText: 'Yes', throughAgent: true);
        $this->storeOld($declined, 'declined', closeNote: 'Not now');
        $this->storeOld($doneByHand, 'done');
        $this->storeOld($doneByCommand, 'done');
        $this->storeOld($withdrawn, 'withdrawn', closeNote: 'No longer needed');
        $this->storeOld($workflowWithdrawn, 'withdrawn', closeNote: 'The rule stopped holding.');
        $this->storeOld($workflowAnswered, 'answered');
        $this->storeOld($obsolete, 'obsolete');
        $this->storeOld($waitDone, 'done');
        $this->storeOld($noticeKey, 'done', closeNote: 'inbox.bridge_outage.closed');
        $this->storeOld($noticeSentence, 'done', closeNote: 'A check found no blocking gap.');
        $this->storeOld($noticeObsolete, 'obsolete');
        $this->storeOld($reviewByPerson, 'done');
        $this->storeOld($reviewByRule, 'done');
        $this->storeOld($automaticReviewEnded, 'done');
        $this->connection->executeStatement('UPDATE inbox_reviews SET note = ? WHERE id = ?', ['Add a retry limit.', $reviewRowIds[0]]);

        $this->migrate('up');

        self::assertSame(['resolved', 'owner', null, 'Both'], $this->record($answered));
        self::assertSame(['resolved', 'owner-through-agent', null, 'Yes'], $this->record($answeredByAgent));
        self::assertSame(['declined', 'owner', null, 'Not now'], $this->record($declined));
        self::assertSame(['resolved', 'owner', null, null], $this->record($doneByHand));
        self::assertSame(['resolved', 'loupe', 'command-succeeded', null], $this->record($doneByCommand));
        self::assertSame(['cancelled', 'agent', 'withdrawn', 'No longer needed'], $this->record($withdrawn));
        self::assertSame(['cancelled', 'rule', 'rule-withdrew', 'The rule stopped holding.'], $this->record($workflowWithdrawn));
        self::assertSame(['resolved', 'owner', null, null], $this->record($workflowAnswered));
        self::assertSame(['cancelled', 'loupe', 'card-finished', null], $this->record($obsolete));
        self::assertSame(['resolved', 'loupe', 'cause-ended', null], $this->record($waitDone));
        self::assertSame(['resolved', 'loupe', 'fact-ended', null], $this->record($noticeKey));
        self::assertSame(['resolved', 'loupe', 'fact-ended', 'A check found no blocking gap.'], $this->record($noticeSentence));
        self::assertSame(['cancelled', 'loupe', 'card-finished', null], $this->record($noticeObsolete));
        self::assertSame(['resolved', 'owner', null, 'Add a retry limit.'], $this->record($reviewByPerson));
        self::assertSame(['resolved', 'rule', null, null], $this->record($reviewByRule));
        self::assertSame(['resolved', 'loupe', 'cause-ended', null], $this->record($automaticReviewEnded));
        self::assertSame(['open', null, null, null], $this->record($open));
    }

    public function test_dismissible_is_true_on_every_stored_row_except_notices_and_workflow_questions(): void
    {
        $question = $this->item(InboxItemKind::Question);
        $todo = $this->item(InboxItemKind::Todo);
        $wait = $this->item(InboxItemKind::Wait, InboxAskOrigin::Loupe);
        $notice = $this->item(InboxItemKind::Notice, InboxAskOrigin::Loupe);
        $workflow = $this->item(InboxItemKind::Workflow, InboxAskOrigin::Loupe);
        $this->em->flush();

        $this->migrate('down');
        $this->migrate('up');

        self::assertSame(
            [true, true, true, false, false],
            array_map($this->dismissible(...), [$question, $todo, $wait, $notice, $workflow]),
        );
    }

    public function test_a_notice_or_a_workflow_question_the_old_app_inserts_gets_dismissible_false(): void
    {
        $inserted = [];
        foreach (['notice', 'workflow', 'question', 'wait'] as $kind) {
            $id = (string) Uuid::v7();
            // The old app names no dismissible column, so the database default applies.
            $this->connection->executeStatement(
                "INSERT INTO inbox_items (id, state, selected_options, updated_at, number, kind, title, blocking, options, multiple, free_text, created_at, project_id, origin)
                 VALUES (?, 'open', '[]', NOW(), ?, ?, 'Inserted by the old app', false, '[]', false, false, NOW(), ?, 'loupe')",
                [$id, ++$this->number + 100, $kind, (string) $this->project->id],
            );
            $inserted[$kind] = $this->connection->fetchOne('SELECT dismissible FROM inbox_items WHERE id = ?', [$id]);
        }

        self::assertSame(['notice' => false, 'workflow' => false, 'question' => true, 'wait' => true], $inserted);
    }

    public function test_the_trigger_maps_what_the_old_app_writes_to_the_close_record(): void
    {
        $answered = $this->item(InboxItemKind::Question);
        $declined = $this->item(InboxItemKind::Todo);
        $workflow = $this->item(InboxItemKind::Workflow, InboxAskOrigin::Loupe);
        $obsolete = $this->item(InboxItemKind::Todo);
        $wait = $this->item(InboxItemKind::Wait, InboxAskOrigin::Loupe);
        $this->em->flush();

        $this->storeOld($answered, 'answered', answerText: 'CSV', throughAgent: true);
        $this->storeOld($declined, 'declined', closeNote: 'Not now');
        $this->storeOld($workflow, 'withdrawn', closeNote: 'The rule stopped holding.');
        $this->storeOld($obsolete, 'obsolete');
        $this->storeOld($wait, 'done');

        self::assertSame(['resolved', 'owner-through-agent', null, 'CSV'], $this->record($answered));
        self::assertSame(['declined', 'owner', null, 'Not now'], $this->record($declined));
        self::assertSame(['cancelled', 'rule', 'rule-withdrew', 'The rule stopped holding.'], $this->record($workflow));
        self::assertSame(['cancelled', 'loupe', 'card-finished', null], $this->record($obsolete));
        self::assertSame(['resolved', 'loupe', 'cause-ended', null], $this->record($wait));
    }

    public function test_the_trigger_leaves_what_the_new_app_writes_alone(): void
    {
        $declined = $this->item(InboxItemKind::Question);
        $cancelled = $this->item(InboxItemKind::Todo);
        $this->em->flush();

        $this->connection->executeStatement("UPDATE inbox_items SET state = 'declined', closed_by = 'owner-through-agent', note = 'No' WHERE id = ?", [(string) $declined->id]);
        $this->connection->executeStatement("UPDATE inbox_items SET state = 'cancelled', closed_by = 'loupe', close_cause = 'card-deleted' WHERE id = ?", [(string) $cancelled->id]);

        self::assertSame(['declined', 'owner-through-agent', null, 'No'], $this->record($declined));
        self::assertSame(['cancelled', 'loupe', 'card-deleted', null], $this->record($cancelled));
    }

    private function item(InboxItemKind $kind, InboxAskOrigin $origin = InboxAskOrigin::Agent): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: $kind, title: 'Item '.$this->number, blocking: false, origin: $origin);
        $this->em->persist($item);

        return $item;
    }

    private function review(InboxItem $item, ?string $workflowRule, bool $withVerdict = true): InboxReview
    {
        $document = $this->document($this->em, $this->project);
        $document->addVersion('# Design', '<h1>Design</h1>');
        $row = new InboxReview($item, $document);
        if ($withVerdict) {
            $row->verdict = InboxReviewVerdict::Approved;
            $row->documentReview = new Review($document->currentVersion(), Verdict::Approved, $this->project->owner, workflowRule: $workflowRule);
            $this->em->persist($row->documentReview);
        }
        $this->em->persist($row);

        return $row;
    }

    private function commandAction(InboxItem $item): void
    {
        $bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->em->persist($bridge);
        $request = new WorkRequest($this->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number, 'design-export', Bridge::CAPABILITY_RUN_COMMAND, 'inbox:run-command', new \DateTimeImmutable());
        $request->state = WorkRequestState::Done;
        $this->em->persist($request);
        $this->em->flush();
        $action = new InboxAction($item, InboxActionKind::RunCommand, $this->project->owner);
        $action->workRequestId = $request->id;
        $this->em->persist($action);
        $this->em->persist(new WorkerRun(
            project: $this->project,
            bridgeId: $bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id,
            cardNumber: $this->card->number,
            workKind: 'design-export',
            state: WorkerRunState::Succeeded,
            runKey: Uuid::v7(),
            workRequestId: $request->id,
        ));
    }

    /** Writes the way the old app did: an old state and the old fields, none of the new columns. */
    private function storeOld(InboxItem $item, string $state, ?string $answerText = null, ?string $closeNote = null, bool $throughAgent = false): void
    {
        $this->connection->executeStatement(
            'UPDATE inbox_items SET state = ?, answer_text = ?, close_note = ?, responded_through_agent = ? WHERE id = ?',
            [$state, $answerText, $closeNote, $throughAgent ? 'true' : 'false', (string) $item->id],
        );
    }

    /** @return array{string, ?string, ?string, ?string} state, closer, cause and note */
    private function record(InboxItem $item): array
    {
        $row = $this->connection->fetchAssociative('SELECT state, closed_by, close_cause, note FROM inbox_items WHERE id = ?', [(string) $item->id]);
        self::assertIsArray($row);

        return [$row['state'], $row['closed_by'], $row['close_cause'], $row['note']];
    }

    private function dismissible(InboxItem $item): bool
    {
        return (bool) $this->connection->fetchOne('SELECT dismissible FROM inbox_items WHERE id = ?', [(string) $item->id]);
    }

    /** @param 'up'|'down' $direction */
    private function migrate(string $direction): void
    {
        $migration = new Version20261030141507($this->connection, new NullLogger());
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
