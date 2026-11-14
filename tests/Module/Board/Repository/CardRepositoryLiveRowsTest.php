<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\View\BacklogListQuery;
use App\Tests\Module\Board\Controller\BoardScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The two reads of each paginated list that a live page uses to refresh its own rows. */
final class CardRepositoryLiveRowsTest extends KernelTestCase
{
    use BoardScenario;

    private EntityManagerInterface $em;
    private CardRepository $cards;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $this->cards = $cards;
    }

    public function test_the_backlog_rows_by_id_keep_the_cards_that_still_match(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-by-id@example.com'));
        $feature = $this->card($this->em, $project, 'Feature');
        $bug = $this->typed($this->em, $this->card($this->em, $project, 'Bug'), 'bug');
        $moved = $this->card($this->em, $project, 'Moved', 'next');
        $backlog = $this->column($project, 'backlog');
        $ids = [(string) $feature->id, (string) $bug->id, (string) $moved->id];

        $all = $this->cards->findBacklogMatchingByIds($backlog, new BacklogListQuery(), $ids);
        $bugs = $this->cards->findBacklogMatchingByIds($backlog, new BacklogListQuery(type: 'bug'), $ids);

        self::assertEqualsCanonicalizing(['Feature', 'Bug'], array_map(static fn (Card $card): string => $card->title, $all));
        self::assertSame(['Bug'], array_map(static fn (Card $card): string => $card->title, $bugs));
        self::assertSame([], $this->cards->findBacklogMatchingByIds($backlog, new BacklogListQuery(), []));
    }

    public function test_the_backlog_counts_the_matching_cards_created_after_the_moment(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-newer@example.com'));
        $this->card($this->em, $project, 'Before');
        $since = new \DateTimeImmutable('+1 second');
        $backlog = $this->column($project, 'backlog');
        $this->card($this->em, $project, 'Other column', 'next');
        $this->em->flush();

        // Guard: a cursor in the past counts the card, so the zero below comes from the cursor.
        self::assertSame(1, $this->cards->countBacklogMatchingCreatedAfter($backlog, new BacklogListQuery(), new \DateTimeImmutable('-1 hour')));
        self::assertSame(0, $this->cards->countBacklogMatchingCreatedAfter($backlog, new BacklogListQuery(), $since));
        self::assertSame(0, $this->cards->countBacklogMatchingCreatedAfter($backlog, new BacklogListQuery(type: 'bug'), new \DateTimeImmutable('-1 hour')));
    }

    public function test_the_terminal_rows_by_id_keep_the_cards_still_in_the_column(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-done@example.com'));
        $done = $this->card($this->em, $project, 'Done card', 'done');
        $open = $this->card($this->em, $project, 'Open card', 'next');
        $column = $this->column($project, 'done');

        $rows = $this->cards->findCompletedByIds($column, [(string) $done->id, (string) $open->id]);

        self::assertSame(['Done card'], array_map(static fn (Card $card): string => $card->title, $rows));
        self::assertSame([], $this->cards->findCompletedByIds($column, []));
    }

    public function test_the_terminal_column_counts_the_cards_completed_after_the_moment(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-done-newer@example.com'));
        $this->card($this->em, $project, 'Done card', 'done');
        $column = $this->column($project, 'done');

        self::assertSame(1, $this->cards->countCompletedAfter($column, new \DateTimeImmutable('-1 hour')));
        self::assertSame(0, $this->cards->countCompletedAfter($column, new \DateTimeImmutable('+1 hour')));
    }
}
