<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\DeleteFeedbackCommand;
use App\Module\Board\Command\DeleteFeedbackHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Service\CardCommentEditLock;
use App\Module\Board\Service\CardCommentLinkResolver;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\CommentLocked;
use App\Module\SiteReview\Command\UpdateCommentCommand;
use App\Module\SiteReview\Command\UpdateCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardCommentEditLockTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardCommentEditLock $lock;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $lock = self::getContainer()->get(CardCommentEditLock::class);
        self::assertInstanceOf(CardCommentEditLock::class, $lock);
        $this->lock = $lock;
    }

    public function test_a_note_on_an_untouched_backlog_card_stays_open(): void
    {
        [, , $comment] = $this->note('lock-open');

        self::assertFalse($this->lock->isLocked($comment));
    }

    public function test_a_card_that_went_to_next_and_back_keeps_its_note_locked(): void
    {
        [$project, $card, $comment] = $this->note('lock-back');

        $card->column = $this->column($project, 'next');
        $this->em->flush();
        $card->column = $this->column($project, 'backlog');
        $this->em->flush();

        self::assertTrue($card->column->backlog);
        self::assertTrue($this->lock->isLocked($comment));
    }

    public function test_a_note_saved_on_the_preview_page_of_its_own_card_stays_open(): void
    {
        [$project, $card, $comment] = $this->note('lock-preview', context: true);
        $card->column = $this->column($project, 'next');
        $this->em->flush();

        self::assertFalse($this->lock->isLocked($comment));
    }

    public function test_a_card_a_person_made_never_locks(): void
    {
        [$project, $card, $comment] = $this->note('lock-person', origin: Actor::Human);
        $card->column = $this->column($project, 'next');
        $this->em->flush();

        self::assertFalse($this->lock->isLocked($comment));
    }

    public function test_the_check_reads_the_stored_flag_behind_a_loaded_entity(): void
    {
        [, $card, $comment] = $this->note('lock-stale');
        self::assertFalse($card->leftBacklog);

        $this->em->getConnection()->executeStatement('UPDATE board_cards SET left_backlog = true WHERE id = ?', [(string) $card->id]);

        self::assertFalse($card->leftBacklog);
        self::assertTrue($this->lock->isLocked($comment));
    }

    public function test_an_edit_and_a_delete_of_a_locked_note_are_refused(): void
    {
        [$project, $card, $comment] = $this->note('lock-refuse');
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET left_backlog = true WHERE id = ?', [(string) $card->id]);
        $commentId = $comment->id ?? throw new \LogicException('comment id');

        $update = self::getContainer()->get(UpdateCommentHandler::class);
        self::assertInstanceOf(UpdateCommentHandler::class, $update);
        $delete = self::getContainer()->get(DeleteFeedbackHandler::class);
        self::assertInstanceOf(DeleteFeedbackHandler::class, $delete);

        try {
            $update(new UpdateCommentCommand($project, $commentId, 'edited'));
            self::fail('The edit should be refused.');
        } catch (CommentLocked) {
        }
        try {
            $delete(new DeleteFeedbackCommand($project, $commentId));
            self::fail('The delete should be refused.');
        } catch (CommentLocked) {
        }

        $this->em->clear();
        $stored = $this->em->find(SiteReviewComment::class, $commentId);
        self::assertInstanceOf(SiteReviewComment::class, $stored);
        self::assertSame('A note', $stored->body);
    }

    public function test_the_list_carries_the_column_and_the_lock_of_each_note(): void
    {
        [$project, $card, $comment] = $this->note('lock-list');
        $resolver = self::getContainer()->get(CardCommentLinkResolver::class);
        self::assertInstanceOf(CardCommentLinkResolver::class, $resolver);

        $open = $resolver->resolve([$comment], $project)[(string) $comment->id];
        self::assertFalse($open->locked);
        self::assertSame((string) $card->id, $open->cardId);

        $card->column = $this->column($project, 'next');
        $this->em->flush();

        $started = $resolver->resolve([$comment], $project)[(string) $comment->id];
        self::assertTrue($started->locked);
        self::assertSame($card->column->label, $started->column);
    }

    /** @return array{Project, Card, SiteReviewComment} */
    private function note(string $slug, bool $context = false, Actor $origin = Actor::Reviewer): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug);
        $this->em->persist($project);
        $this->seedColumns($project);
        $card = new Card($project, $this->column($project, 'backlog'), 'Footer overlaps the launcher', 'body', 4, origin: $origin);
        $this->em->persist($card);
        $this->em->flush();
        $comment = new SiteReviewComment($project, 0, 'A note', 'https://example.com', context: $context ? 'card:'.$card->id : null);
        $this->em->persist($comment);
        $this->em->persist(new CardSiteReviewComment($card, $comment));
        $this->em->flush();

        return [$project, $card, $comment];
    }
}
