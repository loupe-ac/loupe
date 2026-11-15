<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\View\BacklogListQuery;
use App\Module\Workflow\Contract\Actor;
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

    public function test_the_backlog_counts_the_matching_cards_that_arrived_after_the_moment(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-newer@example.com'));
        $this->card($this->em, $project, 'Before');
        $since = new \DateTimeImmutable('+1 second');
        $backlog = $this->column($project, 'backlog');
        $this->card($this->em, $project, 'Other column', 'next');
        $this->em->flush();

        // Guard: a cursor in the past counts the card, so the zero below comes from the cursor.
        self::assertSame(1, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(), new \DateTimeImmutable('-1 hour')));
        self::assertSame(0, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(), $since));
        self::assertSame(0, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(type: 'bug'), new \DateTimeImmutable('-1 hour')));
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

    public function test_a_later_page_compares_arrivals_with_the_cursor_to_the_microsecond(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-micro@example.com'));
        $card = $this->card($this->em, $project, 'Moved in');
        $backlog = $this->column($project, 'backlog');
        $base = new \DateTimeImmutable('+1 hour');
        $second = $base->setTime((int) $base->format('H'), (int) $base->format('i'), (int) $base->format('s'), 0);
        $cursor = $second->modify('+500000 microseconds');
        $this->events()->record($card, CardEventKind::Moved, Actor::System, null, ['from' => CardEvent::columnDetail($this->column($project, 'next')), 'to' => CardEvent::columnDetail($backlog), 'cause' => null], $second->modify('+200000 microseconds'));
        $this->em->flush();

        self::assertSame([], $this->events()->findCardIdsMovedIntoColumnAfter($backlog, $cursor));
        self::assertSame([(string) $card->id], $this->events()->findCardIdsMovedIntoColumnAfter($backlog, $second->modify('+100000 microseconds')));

        $done = $this->card($this->em, $project, 'Done card', 'done');
        $done->completedAt = $second->modify('+700000 microseconds');
        $this->em->flush();
        $this->em->clear();
        $column = $this->column($project, 'done');

        self::assertSame(1, $this->cards->countCompletedAfter($column, $cursor));
        self::assertSame(0, $this->cards->countCompletedAfter($column, $second->modify('+800000 microseconds')));
    }

    public function test_the_backlog_counts_a_card_moved_back_in_after_the_moment(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-moved-back@example.com'));
        $card = $this->card($this->em, $project, 'Back again');
        $backlog = $this->column($project, 'backlog');
        $since = new \DateTimeImmutable('+1 second');
        $this->events()->record($card, CardEventKind::Moved, Actor::System, null, ['from' => CardEvent::columnDetail($this->column($project, 'next')), 'to' => CardEvent::columnDetail($backlog), 'cause' => null], new \DateTimeImmutable('+2 seconds'));
        $this->em->flush();

        $movedIds = $this->events()->findCardIdsMovedIntoColumnAfter($backlog, $since);

        self::assertSame([(string) $card->id], $movedIds);
        self::assertSame(0, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(), $since));
        self::assertSame(1, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(), $since, $movedIds));
        self::assertSame(0, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(type: 'bug'), $since, $movedIds));
    }

    public function test_the_backlog_counts_a_card_edited_into_the_filters_after_the_moment(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-edited-in@example.com'));
        $card = $this->card($this->em, $project, 'Becomes a bug');
        $shown = $this->card($this->em, $project, 'Already shown');
        $backlog = $this->column($project, 'backlog');
        $since = new \DateTimeImmutable('+1 second');
        $bugs = new BacklogListQuery(type: 'bug');

        self::assertSame(0, $this->cards->countBacklogMatchingArrivedAfter($backlog, $bugs, $since));

        foreach ([$card, $shown] as $edited) {
            $edited->type = 'bug';
            $edited->updatedAt = new \DateTimeImmutable('+2 seconds');
        }
        $this->em->flush();

        self::assertSame(2, $this->cards->countBacklogMatchingArrivedAfter($backlog, $bugs, $since));
        self::assertSame(1, $this->cards->countBacklogMatchingArrivedAfter($backlog, $bugs, $since, [], [(string) $shown->id]));
        self::assertSame(0, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(type: 'feature'), $since));
    }

    public function test_an_edit_of_a_card_that_already_matched_adds_no_card_without_a_filter(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-edit-nofilter@example.com'));
        $card = $this->card($this->em, $project, 'Already listed');
        $backlog = $this->column($project, 'backlog');
        $since = new \DateTimeImmutable('+1 second');
        $card->title = 'Renamed';
        $card->updatedAt = new \DateTimeImmutable('+2 seconds');
        $this->em->flush();

        self::assertSame(0, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(), $since));
        self::assertSame(1, $this->cards->countBacklogMatchingArrivedAfter($backlog, new BacklogListQuery(), new \DateTimeImmutable('-1 hour')));
    }

    public function test_a_move_into_another_column_or_before_the_moment_is_not_a_move_in(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-moved-else@example.com'));
        $card = $this->card($this->em, $project, 'Elsewhere');
        $backlog = $this->column($project, 'backlog');
        $next = $this->column($project, 'next');
        $since = new \DateTimeImmutable('+1 second');
        $this->events()->record($card, CardEventKind::Moved, Actor::System, null, ['from' => CardEvent::columnDetail($backlog), 'to' => CardEvent::columnDetail($next), 'cause' => null], new \DateTimeImmutable('+2 seconds'));
        $this->events()->record($card, CardEventKind::Moved, Actor::System, null, ['from' => CardEvent::columnDetail($next), 'to' => CardEvent::columnDetail($backlog), 'cause' => null], new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        self::assertSame([], $this->events()->findCardIdsMovedIntoColumnAfter($backlog, $since));
    }

    public function test_the_terminal_column_counts_a_card_moved_in_from_another_terminal_column(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-done-moved@example.com'));
        $card = $this->card($this->em, $project, 'Done earlier', 'done');
        $column = $this->column($project, 'done');
        $since = new \DateTimeImmutable('+1 second');
        $this->events()->record($card, CardEventKind::Moved, Actor::System, null, ['from' => [], 'to' => CardEvent::columnDetail($column), 'cause' => null], new \DateTimeImmutable('+2 seconds'));
        $this->em->flush();

        self::assertSame(0, $this->cards->countCompletedAfter($column, $since));
        self::assertSame(1, $this->cards->countCompletedAfter($column, $since, $this->events()->findCardIdsMovedIntoColumnAfter($column, $since)));
    }

    public function test_the_terminal_column_does_not_count_a_shown_card_that_left_and_returned(): void
    {
        $project = $this->project($this->em, $this->user($this->em, 'rows-done-shown@example.com'));
        $card = $this->card($this->em, $project, 'Shown', 'done');
        $column = $this->column($project, 'done');
        $since = new \DateTimeImmutable('+1 second');
        $this->events()->record($card, CardEventKind::Moved, Actor::System, null, ['from' => [], 'to' => CardEvent::columnDetail($column), 'cause' => null], new \DateTimeImmutable('+2 seconds'));
        $this->em->flush();
        $moved = $this->events()->findCardIdsMovedIntoColumnAfter($column, $since);

        self::assertSame(1, $this->cards->countCompletedAfter($column, $since, $moved));
        self::assertSame(0, $this->cards->countCompletedAfter($column, $since, $moved, [(string) $card->id]));
    }

    private function events(): CardEventRepository
    {
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);

        return $events;
    }
}
