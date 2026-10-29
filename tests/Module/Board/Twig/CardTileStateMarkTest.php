<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerLink;
use App\Tests\Module\Board\Controller\BoardScenario;
use App\Tests\Support\MarkerMother;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class CardTileStateMarkTest extends KernelTestCase
{
    use BoardScenario;

    /** @return iterable<string, array{string, MarkerKind, array<string, string|int>, string, string}> */
    public static function kinds(): iterable
    {
        yield 'stuck' => ['ready_not_merged', MarkerKind::Stuck, ['%number%' => 12], 'Stuck', 'Pull request #12 stayed ready to merge past the stuck delay, and no merge is under way.'];
        yield 'needs you' => ['open_question', MarkerKind::NeedsYou, ['%number%' => 3, '%title%' => 'Which option?'], 'Needs you', 'Question #3 waits for your answer: Which option?'];
        yield 'working' => ['run_open', MarkerKind::Working, [], 'Working', 'A worker runs on this card.'];
        yield 'waiting' => ['held_by_blocker', MarkerKind::Waiting, ['%number%' => 7, '%title%' => 'The blocker'], 'Waiting', 'Card #7 blocks this card: The blocker'];
        yield 'chip only' => ['unmanaged', MarkerKind::Chip, [], 'Note', 'Unmanaged: the workflow does not act on this card.'];
    }

    /** @param array<string, string|int> $params */
    #[DataProvider('kinds')]
    public function test_a_tile_draws_one_mark_with_its_kind_name_reason_and_link(string $key, MarkerKind $kind, array $params, string $label, string $sentence): void
    {
        $tile = $this->tile(MarkerMother::markers(MarkerMother::marker($key, $kind, $params)));

        $mark = $tile->filter('.lp-board-card__identity .lp-state-mark');
        self::assertCount(1, $tile->filter('.lp-state-mark'));
        self::assertCount(1, $mark);
        self::assertStringContainsString('lp-state-mark--'.$kind->value, (string) $mark->attr('class'));
        self::assertSame($label, $mark->filter('[role="img"]')->attr('aria-label'));
        self::assertSame('0', $mark->filter('[role="img"]')->attr('tabindex'));

        $tooltip = $mark->filter('.lp-tooltip--interactive');
        self::assertSame($mark->filter('[role="img"]')->attr('aria-describedby'), $tooltip->attr('id'));
        self::assertStringContainsString($sentence, $tooltip->text());
        self::assertStringContainsString($label, trim($tooltip->filter('.lp-tooltip__title')->text()));
        self::assertSame('lp-state-mark', $tile->filter('.lp-board-card__identity > :not(.lp-board-card__move)')->last()->attr('class') |> (static fn (?string $class): string => explode(' ', (string) $class)[0]));
        $link = $tooltip->filter('a');
        self::assertSame('Open card', trim($link->text()));
        self::assertSame('card-drawer-frame', $link->attr('data-turbo-frame'));
        self::assertSame('click->card-drawer#prepare', $link->attr('data-action'));
        self::assertSame('false', $link->attr('draggable'));
        self::assertStringEndsWith('/board/cards/'.$tile->attr('data-card-id'), (string) $link->attr('href'));
    }

    public function test_a_paused_tile_says_the_pause_reason_in_words(): void
    {
        $tile = $this->tile(MarkerMother::markers(MarkerMother::marker('paused', MarkerKind::Stuck, ['%reason%' => 'failed'])));

        $tooltip = $tile->filter('.lp-tooltip');
        self::assertStringContainsString('Paused: The work failed.', $tooltip->text());
        self::assertStringNotContainsString('Paused: failed.', $tooltip->text());
    }

    public function test_a_state_marker_with_a_link_adds_it_to_the_tile_tooltip_and_not_to_a_row(): void
    {
        $card = $this->storedCard();
        $markers = MarkerMother::markers(MarkerMother::marker('no_bridge_online', MarkerKind::Stuck, link: MarkerLink::Agents));

        $links = $this->renderTile($card, $markers)->filter('.lp-tooltip a');
        self::assertSame(['Open the Agents page', 'Open card'], $links->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertStringEndsWith('/projects/'.$card->project->id.'/agents', (string) $links->first()->attr('href'));
        self::assertSame('_top', $links->first()->attr('data-turbo-frame'));
        self::assertStringContainsString('No bridge is online to take this work.', $this->renderTile($card, $markers)->filter('.lp-tooltip')->text());
        self::assertCount(0, $this->renderRow($card, $markers)->filter('.lp-state-mark a'));
    }

    public function test_a_repository_misfit_marker_links_the_tile_tooltip_to_the_repositories_page(): void
    {
        $card = $this->storedCard();
        $markers = MarkerMother::markers(MarkerMother::marker('repository_misfit', MarkerKind::NeedsYou, link: MarkerLink::Repositories));

        $links = $this->renderTile($card, $markers)->filter('.lp-tooltip a');
        self::assertSame(['Open Repositories', 'Open card'], $links->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertStringEndsWith('/projects/'.$card->project->id.'/settings/repositories', (string) $links->first()->attr('href'));
        self::assertStringContainsString('New work is paused: a repository does not fit the workflow.', $this->renderTile($card, $markers)->filter('.lp-tooltip')->text());
    }

    public function test_a_needs_you_marker_after_the_state_stays_off_the_tile_tooltip_with_its_link(): void
    {
        $card = $this->storedCard();
        $markers = MarkerMother::markers(
            MarkerMother::marker('merge_blocked', MarkerKind::NeedsYou, ['%number%' => 9]),
            MarkerMother::marker('repository_misfit', MarkerKind::NeedsYou, link: MarkerLink::Repositories),
        );

        $tooltip = $this->renderTile($card, $markers)->filter('.lp-tooltip');
        self::assertSame(['merge_blocked'], $tooltip->filter('[data-marker-key]')->each(static fn (Crawler $row): string => (string) $row->attr('data-marker-key')));
        self::assertSame(['Open card'], $tooltip->filter('a')->each(static fn (Crawler $link): string => trim($link->text())));
    }

    public function test_two_stuck_markers_show_one_stuck_line(): void
    {
        $tile = $this->tile(MarkerMother::markers(
            MarkerMother::marker('no_capable_bridge', MarkerKind::Stuck, link: MarkerLink::Agents),
            MarkerMother::marker('no_bridge_online', MarkerKind::Stuck, link: MarkerLink::Agents),
        ));

        self::assertSame(1, substr_count($tile->filter('.lp-tooltip')->text(), 'Stuck.'));
        self::assertSame(['no_capable_bridge'], $tile->filter('.lp-tooltip [data-marker-key]')->each(static fn (Crawler $row): string => (string) $row->attr('data-marker-key')));
        self::assertSame(['Open the Agents page', 'Open card'], $tile->filter('.lp-tooltip a')->each(static fn (Crawler $link): string => trim($link->text())));
    }

    public function test_an_open_inbox_item_adds_its_link_to_the_tile_tooltip_and_not_to_a_row(): void
    {
        $card = $this->storedCard();
        $markers = MarkerMother::markers(MarkerMother::marker('no_bridge_online', MarkerKind::Stuck, link: MarkerLink::Agents));

        $links = $this->renderTile($card, $markers, inboxItem: 42)->filter('.lp-tooltip a');
        self::assertSame(['Open the Agents page', 'Open inbox item', 'Open card'], $links->each(static fn (Crawler $link): string => trim($link->text())));
        $inbox = $links->filter('[data-card-inbox-item]');
        self::assertSame('/projects/'.$card->project->id.'/inbox?item=42', $inbox->attr('href'));
        self::assertSame('_top', $inbox->attr('data-turbo-frame'));
        self::assertSame('false', $inbox->attr('draggable'));
        self::assertCount(0, $this->renderRow($card, $markers, inboxItem: 42)->filter('.lp-state-mark a'));
    }

    public function test_the_digest_of_the_tile_and_of_the_row_move_with_the_inbox_item(): void
    {
        $card = $this->storedCard();
        $markers = MarkerMother::markers(MarkerMother::marker('run_open', MarkerKind::Working));
        $none = $this->renderTile($card, $markers)->attr('data-card-digest');
        $linked = $this->renderTile($card, $markers, inboxItem: 42)->attr('data-card-digest');

        self::assertNotSame($none, $linked);
        self::assertSame($linked, $this->renderRow($card, $markers, inboxItem: 42)->attr('data-card-digest'));
    }

    public function test_a_link_on_another_marker_stays_off_the_tile_tooltip(): void
    {
        $tile = $this->tile(MarkerMother::markers(
            MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]),
            MarkerMother::marker('bridge_paused', MarkerKind::Waiting, link: MarkerLink::Agents),
        ));

        self::assertSame(['Open card'], $tile->filter('.lp-tooltip a')->each(static fn (Crawler $link): string => trim($link->text())));
    }

    public function test_the_mark_icon_follows_the_kind(): void
    {
        $waiting = $this->tile(MarkerMother::markers(MarkerMother::marker('held_by_blocker', MarkerKind::Waiting, ['%number%' => 7, '%title%' => 'x'])));
        $chip = $this->tile(MarkerMother::markers(MarkerMother::marker('unmanaged', MarkerKind::Chip)));

        self::assertCount(1, $waiting->filter('.lp-state-mark__glyph'));
        self::assertNotSame($waiting->filter('.lp-state-mark__glyph')->outerHtml(), $chip->filter('.lp-state-mark__glyph')->outerHtml());
        self::assertStringContainsString('d="M10.1 2.182a10 10 0 0 1 3.8 0', $chip->filter('.lp-state-mark__glyph')->outerHtml());
        self::assertStringNotContainsString('M10.1 2.182', $waiting->filter('.lp-state-mark__glyph')->outerHtml());
        self::assertStringContainsString('d="M5 22h14M5 2h14', $waiting->filter('.lp-state-mark__glyph')->outerHtml());
    }

    public function test_the_tooltip_shows_the_main_state_alone(): void
    {
        $tile = $this->tile(MarkerMother::markers(
            MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]),
            MarkerMother::marker('run_open', MarkerKind::Working),
            MarkerMother::marker('open_question', MarkerKind::NeedsYou, ['%number%' => 3, '%title%' => 'Which option?']),
            MarkerMother::marker('unmanaged', MarkerKind::Chip),
        ));

        $tooltip = $tile->filter('.lp-tooltip');
        self::assertSame(['checks_failed'], $tooltip->filter('[data-marker-key]')->each(static fn (Crawler $row): string => (string) $row->attr('data-marker-key')));
        self::assertStringContainsString('The checks of pull request #5 fail', $tooltip->text());
        self::assertStringNotContainsString('Which option?', $tooltip->text());
        self::assertStringNotContainsString('Unmanaged', $tooltip->text());
        self::assertStringNotContainsString('A worker runs on this card.', $tooltip->text());
        self::assertCount(1, $tile->filter('.lp-state-mark'));
    }

    public function test_a_running_worker_with_waiting_checks_shows_one_line_in_the_tooltip(): void
    {
        $tile = $this->tile(MarkerMother::markers(
            MarkerMother::marker('run_open', MarkerKind::Working),
            MarkerMother::marker('waiting_on_checks', MarkerKind::Waiting, ['%number%' => 4]),
        ));

        self::assertSame(['run_open'], $tile->filter('.lp-tooltip [data-marker-key]')->each(static fn (Crawler $row): string => (string) $row->attr('data-marker-key')));
    }

    public function test_a_card_with_only_a_chip_shows_the_chip_label_once_in_the_tooltip(): void
    {
        $tile = $this->tile(MarkerMother::markers(MarkerMother::marker('unmanaged', MarkerKind::Chip)));

        $tooltip = $tile->filter('.lp-tooltip');
        self::assertSame(['unmanaged'], $tooltip->filter('[data-marker-key]')->each(static fn (Crawler $row): string => (string) $row->attr('data-marker-key')));
        self::assertSame(1, substr_count($tooltip->text(), 'Unmanaged: the workflow does not act on this card.'));
    }

    public function test_the_since_line_shows_the_time_of_the_marker(): void
    {
        $tile = $this->tile(MarkerMother::markers(MarkerMother::marker('run_open', MarkerKind::Working, since: '2026-10-02 09:45:00')));

        self::assertStringContainsString('Started Oct 2, 09:45, ', $tile->filter('.lp-tooltip')->text());
    }

    public function test_a_card_without_markers_shows_no_mark(): void
    {
        self::assertCount(0, $this->tile(null)->filter('.lp-state-mark'));
    }

    public function test_the_tile_draws_no_badge_and_no_run_warning(): void
    {
        $warning = new CardRunWarning('11111111-1111-4111-8111-111111111111', WorkerRunState::GaveUp, 'It gave up.', new \DateTimeImmutable('2026-10-02 09:00:00'));

        $tile = $this->tile(MarkerMother::markers(MarkerMother::marker('unmanaged', MarkerKind::Chip)), $warning);

        self::assertCount(1, $tile->filter('.lp-state-mark'));
        self::assertCount(0, $tile->filter('.lp-board-card__warning, [data-card-run-warning], [data-card-badges], .lp-status-chip'));
    }

    public function test_the_digest_of_the_tile_and_of_the_row_move_with_the_markers(): void
    {
        $card = $this->storedCard();
        $none = $this->renderTile($card, null)->attr('data-card-digest');
        $marked = $this->renderTile($card, MarkerMother::markers(MarkerMother::marker('run_open', MarkerKind::Working)))->attr('data-card-digest');

        self::assertNotSame($none, $marked);
        self::assertSame($marked, $this->renderRow($card, MarkerMother::markers(MarkerMother::marker('run_open', MarkerKind::Working)))->attr('data-card-digest'));
    }

    public function test_a_list_row_draws_the_mark_without_a_link_inside_the_row_link(): void
    {
        $row = $this->renderRow($this->storedCard(), MarkerMother::markers(
            MarkerMother::marker('held_by_blocker', MarkerKind::Waiting, ['%number%' => 7, '%title%' => 'The blocker']),
            MarkerMother::marker('unmanaged', MarkerKind::Chip),
        ));

        $mark = $row->filter('.lp-board-list__work .lp-state-mark');
        self::assertCount(1, $mark);
        self::assertStringContainsString('lp-state-mark--waiting', (string) $mark->attr('class'));
        self::assertStringContainsString('Card #7 blocks this card: The blocker', $mark->filter('.lp-tooltip')->text());
        self::assertCount(0, $mark->filter('[data-marker-key="unmanaged"]'));
        self::assertCount(0, $mark->filter('a'));
        self::assertCount(1, $row->filter('.lp-board-list__work > strong'));
        self::assertCount(0, $row->filter('[data-card-badges], .lp-status-chip'));
    }

    public function test_a_row_with_only_chips_draws_the_neutral_mark(): void
    {
        $row = $this->renderRow($this->storedCard(), MarkerMother::markers(MarkerMother::marker('unmanaged', MarkerKind::Chip)));

        self::assertCount(1, $row->filter('.lp-state-mark--chip'));
        self::assertSame('Note.', trim($row->filter('.lp-tooltip__title')->text()));
        self::assertStringContainsString('Unmanaged: the workflow does not act on this card.', $row->filter('.lp-tooltip')->text());
    }

    public function test_a_row_without_markers_draws_no_mark(): void
    {
        self::assertCount(0, $this->renderRow($this->storedCard(), null)->filter('.lp-state-mark'));
    }

    private function tile(?StoredCardMarkers $markers, ?CardRunWarning $runWarning = null): Crawler
    {
        return $this->renderTile($this->storedCard(), $markers, $runWarning);
    }

    private function storedCard(): Card
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'tile-'.bin2hex(random_bytes(4)).'@example.com');
        $project = $this->project($em, $owner);

        return $this->card($em, $project, 'The card', 'in-progress');
    }

    private function renderTile(Card $card, ?StoredCardMarkers $markers, ?CardRunWarning $runWarning = null, ?int $inboxItem = null): Crawler
    {
        $html = static::getContainer()->get(Environment::class)->render('@Board/_card.html.twig', [
            'card' => $card,
            'pendingComments' => 0,
            'documentCount' => 0,
            'markers' => $markers,
            'runWarning' => $runWarning,
            'inboxItem' => $inboxItem,
        ]);

        return new Crawler($html)->filter('article.lp-board-card');
    }

    private function renderRow(Card $card, ?StoredCardMarkers $markers, ?int $inboxItem = null): Crawler
    {
        $html = static::getContainer()->get(Environment::class)->render('@Board/_card_row.html.twig', [
            'card' => $card,
            'column' => $card->column,
            'feedbackCount' => 0,
            'documentCount' => 0,
            'progress' => null,
            'runWarning' => null,
            'markers' => $markers,
            'inboxItem' => $inboxItem,
        ]);

        return new Crawler($html)->filter('a.lp-board-list__row');
    }
}
