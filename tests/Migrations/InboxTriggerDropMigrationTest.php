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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261101222810;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../migrations/Version20261101222810.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class InboxTriggerDropMigrationTest extends KernelTestCase
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
        $this->project = $this->project($em, $this->owner($em, 'trigger-drop'), 'trigger-drop');
        $this->card = $this->card($em, $this->project);
        $em->flush();
    }

    public function test_the_schema_has_none_of_the_triggers_the_functions_or_the_old_columns(): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_trigger WHERE tgname IN ('inbox_items_map_old_writes', 'inbox_reviews_map_old_writes')"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_proc WHERE proname IN ('inbox_items_map_old_writes', 'inbox_reviews_map_old_writes', 'inbox_items_close_record')"));
        self::assertSame(0, $this->columns('inbox_items', ['answer_text', 'close_note', 'responded_through_agent']));
        self::assertSame(0, $this->columns('inbox_reviews', ['note']));
    }

    public function test_down_brings_the_old_columns_back_filled_from_the_close_record_and_up_drops_them_again(): void
    {
        $question = $this->item(InboxItemKind::Question);
        $this->em->flush();
        $this->connection->executeStatement("UPDATE inbox_items SET state = 'resolved', closed_by = 'owner-through-agent', note = 'CSV' WHERE id = ?", [(string) $question->id]);

        $this->migrate(down: true);

        self::assertSame(3, $this->columns('inbox_items', ['answer_text', 'close_note', 'responded_through_agent']));
        self::assertSame(1, $this->columns('inbox_reviews', ['note']));
        self::assertSame(
            ['CSV', null, true],
            array_values($this->connection->fetchAssociative('SELECT answer_text, close_note, responded_through_agent FROM inbox_items WHERE id = ?', [(string) $question->id]) ?: []),
        );

        $this->migrate();

        self::assertSame(0, $this->columns('inbox_items', ['answer_text', 'close_note', 'responded_through_agent']));
        self::assertSame(0, $this->columns('inbox_reviews', ['note']));
    }

    public function test_up_keeps_the_closer_the_cause_and_the_note_of_each_row_left_in_an_old_state(): void
    {
        $answered = $this->item(InboxItemKind::Question);
        $answeredByAgent = $this->item(InboxItemKind::Question);
        $declined = $this->item(InboxItemKind::Todo);
        $doneByHand = $this->item(InboxItemKind::Todo);
        $doneByCommand = $this->item(InboxItemKind::Todo);
        $withdrawn = $this->item(InboxItemKind::Question);
        $workflowWithdrawn = $this->item(InboxItemKind::Workflow, InboxAskOrigin::Loupe);
        $obsolete = $this->item(InboxItemKind::Todo);
        $waitDone = $this->item(InboxItemKind::Wait, InboxAskOrigin::Loupe);
        $noticeKey = $this->item(InboxItemKind::Notice, InboxAskOrigin::Loupe);
        $noticeSentence = $this->item(InboxItemKind::Notice, InboxAskOrigin::Loupe);
        $reviewByPerson = $this->item(InboxItemKind::Review);
        $reviewByRule = $this->item(InboxItemKind::Review);
        $automaticReviewEnded = $this->item(InboxItemKind::Review, InboxAskOrigin::Loupe);
        $open = $this->item(InboxItemKind::Question);

        $reviewRowByPerson = $this->review($reviewByPerson, null);
        $this->review($reviewByRule, 'tech-design-auto-approve');
        $this->review($automaticReviewEnded, null, withVerdict: false);
        $this->commandAction($doneByCommand);
        $this->em->flush();

        $this->migrate(down: true);
        $this->storeOld($answered, 'answered', answerText: 'Both');
        $this->storeOld($answeredByAgent, 'answered', answerText: 'Yes', throughAgent: true);
        $this->storeOld($declined, 'declined', closeNote: 'Not now');
        $this->storeOld($doneByHand, 'done');
        $this->storeOld($doneByCommand, 'done');
        $this->storeOld($withdrawn, 'withdrawn', closeNote: 'No longer needed');
        $this->storeOld($workflowWithdrawn, 'withdrawn', closeNote: 'The rule stopped holding.');
        $this->storeOld($obsolete, 'obsolete');
        $this->storeOld($waitDone, 'done');
        $this->storeOld($noticeKey, 'done', closeNote: 'inbox.bridge_outage.closed');
        $this->storeOld($noticeSentence, 'done', closeNote: 'A check found no blocking gap.');
        $this->storeOld($reviewByPerson, 'done');
        $this->storeOld($reviewByRule, 'done');
        $this->storeOld($automaticReviewEnded, 'done');
        $this->connection->executeStatement('UPDATE inbox_reviews SET note = ? WHERE id = ?', ['Add a retry limit.', (string) $reviewRowByPerson->id]);

        $this->migrate();

        self::assertSame(['resolved', 'owner', null, 'Both'], $this->record($answered));
        self::assertSame(['resolved', 'owner-through-agent', null, 'Yes'], $this->record($answeredByAgent));
        self::assertSame(['declined', 'owner', null, 'Not now'], $this->record($declined));
        self::assertSame(['resolved', 'owner', null, null], $this->record($doneByHand));
        self::assertSame(['resolved', 'loupe', 'command-succeeded', null], $this->record($doneByCommand));
        self::assertSame(['cancelled', 'agent', 'withdrawn', 'No longer needed'], $this->record($withdrawn));
        self::assertSame(['cancelled', 'rule', 'rule-withdrew', 'The rule stopped holding.'], $this->record($workflowWithdrawn));
        self::assertSame(['cancelled', 'loupe', 'card-finished', null], $this->record($obsolete));
        self::assertSame(['resolved', 'loupe', 'cause-ended', null], $this->record($waitDone));
        self::assertSame(['resolved', 'loupe', 'fact-ended', null], $this->record($noticeKey));
        self::assertSame(['resolved', 'loupe', 'fact-ended', 'A check found no blocking gap.'], $this->record($noticeSentence));
        self::assertSame(['resolved', 'owner', null, 'Add a retry limit.'], $this->record($reviewByPerson));
        self::assertSame(['resolved', 'rule', null, null], $this->record($reviewByRule));
        self::assertSame(['resolved', 'loupe', 'cause-ended', null], $this->record($automaticReviewEnded));
        self::assertSame(['open', null, null, null], $this->record($open));
    }

    public function test_up_copies_a_review_note_onto_a_closed_item_and_keeps_an_existing_note(): void
    {
        $noteOnReview = $this->item(InboxItemKind::Review);
        $noteOnBoth = $this->item(InboxItemKind::Review);
        $rowNoteOnReview = $this->review($noteOnReview, null);
        $rowNoteOnBoth = $this->review($noteOnBoth, null);
        $this->em->flush();
        $this->connection->executeStatement("UPDATE inbox_items SET state = 'resolved', closed_by = 'owner' WHERE id = ?", [(string) $noteOnReview->id]);
        $this->connection->executeStatement("UPDATE inbox_items SET state = 'resolved', closed_by = 'owner', note = 'Stored' WHERE id = ?", [(string) $noteOnBoth->id]);

        $this->migrate(down: true);
        $this->connection->executeStatement('UPDATE inbox_reviews SET note = ? WHERE id = ?', ['Written late', (string) $rowNoteOnReview->id]);
        $this->connection->executeStatement('UPDATE inbox_reviews SET note = ? WHERE id = ?', ['Written late', (string) $rowNoteOnBoth->id]);

        $this->migrate();

        self::assertSame(['resolved', 'owner', null, 'Written late'], $this->record($noteOnReview));
        self::assertSame(['resolved', 'owner', null, 'Stored'], $this->record($noteOnBoth));
    }

    public function test_up_leaves_a_row_in_a_new_state_alone(): void
    {
        $declined = $this->item(InboxItemKind::Question);
        $cancelled = $this->item(InboxItemKind::Todo);
        $this->em->flush();
        $this->connection->executeStatement("UPDATE inbox_items SET state = 'declined', closed_by = 'owner-through-agent', note = 'No' WHERE id = ?", [(string) $declined->id]);
        $this->connection->executeStatement("UPDATE inbox_items SET state = 'cancelled', closed_by = 'loupe', close_cause = 'card-deleted' WHERE id = ?", [(string) $cancelled->id]);

        $this->migrate(down: true);
        $this->migrate();

        self::assertSame(['declined', 'owner-through-agent', null, 'No'], $this->record($declined));
        self::assertSame(['cancelled', 'loupe', 'card-deleted', null], $this->record($cancelled));
    }

    public function test_up_sets_dismissible_false_on_a_notice_and_a_workflow_question_only(): void
    {
        $notice = $this->item(InboxItemKind::Notice, InboxAskOrigin::Loupe);
        $workflow = $this->item(InboxItemKind::Workflow, InboxAskOrigin::Loupe);
        $question = $this->item(InboxItemKind::Question);
        $this->em->flush();
        $this->migrate(down: true);
        $this->connection->executeStatement('UPDATE inbox_items SET dismissible = true WHERE id IN (?, ?)', [(string) $notice->id, (string) $workflow->id]);

        $this->migrate();

        self::assertSame(
            [false, false, true],
            array_map($this->dismissible(...), [$notice, $workflow, $question]),
        );
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

    /** @param list<string> $names */
    private function columns(string $table, array $names): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :table AND column_name IN (:names)',
            ['table' => $table, 'names' => $names],
            ['names' => ArrayParameterType::STRING],
        );
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261101222810($this->connection, new NullLogger());
        $down ? $migration->down(new Schema()) : $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
