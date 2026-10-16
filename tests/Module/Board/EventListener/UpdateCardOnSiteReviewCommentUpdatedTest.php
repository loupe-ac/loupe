<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\AddFeedbackCommand;
use App\Module\Board\Command\AddFeedbackHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\Command\UpdateCommentCommand;
use App\Module\SiteReview\Command\UpdateCommentHandler;
use App\Module\SiteReview\Event\SiteReviewCommentUpdated;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UpdateCardOnSiteReviewCommentUpdatedTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private AddFeedbackHandler $add;
    private UpdateCommentHandler $update;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $add = self::getContainer()->get(AddFeedbackHandler::class);
        self::assertInstanceOf(AddFeedbackHandler::class, $add);
        $this->add = $add;
        $update = self::getContainer()->get(UpdateCommentHandler::class);
        self::assertInstanceOf(UpdateCommentHandler::class, $update);
        $this->update = $update;
    }

    public function test_an_edit_rewrites_the_title_and_the_body_of_an_untouched_card(): void
    {
        $project = $this->project('edit-follow');
        $link = $this->addNote($project, 'Footer overlaps the launcher');

        $this->edit($project, $link, 'Footer overlaps the launcher at 1280px');

        $card = $this->fresh($link);
        $link = $this->em->find(CardSiteReviewComment::class, $link->id);
        self::assertNotNull($link);
        self::assertSame('Footer overlaps the launcher at 1280px', $card->title);
        self::assertSame('Footer overlaps the launcher at 1280px', $card->body);
        self::assertSame($card->title, $link->createdTitle);
        self::assertSame($card->body, $link->createdBody);
    }

    public function test_a_second_edit_still_follows(): void
    {
        $project = $this->project('edit-follow-twice');
        $link = $this->addNote($project, 'One');

        $this->edit($project, $link, 'Two');
        $this->edit($project, $link, 'Three');

        $card = $this->fresh($link);
        self::assertSame('Three', $card->title);
        self::assertSame('Three', $card->body);
    }

    public function test_an_edit_leaves_a_card_that_someone_renamed(): void
    {
        $project = $this->project('edit-renamed');
        $link = $this->addNote($project, 'Footer overlaps the launcher');
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET title = ? WHERE id = ?', ['Fix the footer', (string) $link->card->id]);

        $this->edit($project, $link, 'Something else');

        $card = $this->fresh($link);
        self::assertSame('Fix the footer', $card->title);
        self::assertSame('Footer overlaps the launcher', $card->body);
    }

    public function test_an_edit_leaves_a_card_that_someone_wrote_a_body_for(): void
    {
        $project = $this->project('edit-body');
        $link = $this->addNote($project, 'Footer overlaps the launcher');
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET body = ? WHERE id = ?', ['Repro: resize.', (string) $link->card->id]);

        $this->edit($project, $link, 'Something else');

        $card = $this->fresh($link);
        self::assertSame('Footer overlaps the launcher', $card->title);
        self::assertSame('Repro: resize.', $card->body);
    }

    public function test_an_edit_leaves_a_card_that_someone_moved_out_of_the_backlog(): void
    {
        $project = $this->project('edit-moved');
        $link = $this->addNote($project, 'Footer overlaps the launcher');
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET column_id = ? WHERE id = ?',
            [(string) $this->column($project, 'next')->id, (string) $link->card->id],
        );

        $this->edit($project, $link, 'Something else');

        $card = $this->fresh($link);
        self::assertSame('Footer overlaps the launcher', $card->title);
        self::assertSame('Footer overlaps the launcher', $card->body);
    }

    public function test_an_edit_leaves_a_card_that_a_worker_runs_on(): void
    {
        $project = $this->project('edit-running');
        $link = $this->addNote($project, 'Footer overlaps the launcher');
        $this->em->persist(new WorkerRun(
            project: $project,
            bridgeId: null,
            subjectType: WorkSubject::CARD,
            subjectId: $link->card->id ?? throw new \LogicException('unsaved card'),
            cardNumber: $link->card->number,
            workKind: 'plan',
            state: WorkerRunState::Running,
        ));
        $this->em->flush();

        $this->edit($project, $link, 'Something else');

        $card = $this->fresh($link);
        self::assertSame('Footer overlaps the launcher', $card->title);
        self::assertSame('Footer overlaps the launcher', $card->body);
    }

    public function test_an_edit_leaves_a_card_that_the_note_only_joined(): void
    {
        $project = $this->project('edit-joined');
        $first = $this->addNote($project, 'First note');
        $second = $this->addNote($project, 'Second note', (string) $first->card->id);

        $this->edit($project, $second, 'Second note, edited');

        $card = $this->fresh($first);
        self::assertSame('First note', $card->title);
        self::assertSame('First note', $card->body);
    }

    public function test_a_late_listener_follows_an_edit_that_another_listener_already_applied(): void
    {
        $project = $this->project('edit-race-link');
        $link = $this->addNote($project, 'One');
        $this->applyElsewhere($link, note: 'Three', card: 'Two');

        $this->dispatchEdit($project, $link, 'Three');

        $card = $this->fresh($link);
        self::assertSame('Three', $card->title);
        self::assertSame('Three', $card->body);
    }

    public function test_a_late_event_writes_the_latest_note_rather_than_its_own_body(): void
    {
        $project = $this->project('edit-race-body');
        $link = $this->addNote($project, 'One');
        $this->applyElsewhere($link, note: 'Three', card: 'Two');

        $this->dispatchEdit($project, $link, 'Two');

        $card = $this->fresh($link);
        self::assertSame('Three', $card->title);
        self::assertSame('Three', $card->body);
    }

    /** Another process saved the note and applied an earlier edit to the card and its link. */
    private function applyElsewhere(CardSiteReviewComment $link, string $note, string $card): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE site_review_comments SET body = ? WHERE id = ?', [$note, (string) $link->comment->id]);
        $connection->executeStatement('UPDATE board_cards SET title = ?, body = ? WHERE id = ?', [$card, $card, (string) $link->card->id]);
        $connection->executeStatement(
            'UPDATE board_card_site_review_comments SET created_title = ?, created_body = ? WHERE id = ?',
            [$card, $card, (string) $link->id],
        );
    }

    private function dispatchEdit(Project $project, CardSiteReviewComment $link, string $body): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->dispatch(new SiteReviewCommentUpdated(
            $project->requireId(),
            $link->comment->id ?? throw new \LogicException('unsaved comment'),
            $body,
        ));
    }

    private function fresh(CardSiteReviewComment $link): Card
    {
        $id = $link->card->id;
        $this->em->clear();

        return $this->em->find(Card::class, $id) ?? throw new \LogicException('card gone');
    }

    /** @param non-empty-string $body */
    private function edit(Project $project, CardSiteReviewComment $link, string $body): void
    {
        ($this->update)(new UpdateCommentCommand($project, $link->comment->id ?? throw new \LogicException('unsaved comment'), $body));
    }

    /** @param non-empty-string $body */
    private function addNote(Project $project, string $body, ?string $cardId = null): CardSiteReviewComment
    {
        return ($this->add)(new AddFeedbackCommand(
            project: $project,
            body: $body,
            url: 'https://preview.example/checkout',
            anchors: [new NewAnchor('.footer', 'Footer')],
            cardId: $cardId,
        ));
    }

    private function project(string $label): Project
    {
        $owner = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $label);
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }
}
