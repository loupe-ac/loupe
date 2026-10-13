<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Workflow\Contract\MarkerKind;
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
        yield 'stuck' => ['ready_not_merged', MarkerKind::Stuck, ['%number%' => 12], 'Stuck', 'Pull request #12 is ready to merge, and no merge rule matches it.'];
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
        self::assertSame('lp-state-mark', $tile->filter('.lp-board-card__identity > *')->last()->attr('class') |> (static fn (?string $class): string => explode(' ', (string) $class)[0]));
        $link = $tooltip->filter('a');
        self::assertSame('Open card', trim($link->text()));
        self::assertSame('card-drawer-frame', $link->attr('data-turbo-frame'));
        self::assertSame('click->card-drawer#prepare', $link->attr('data-action'));
        self::assertSame('false', $link->attr('draggable'));
        self::assertStringEndsWith('/board/cards/'.$tile->attr('data-card-id'), (string) $link->attr('href'));
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

    public function test_the_tooltip_lists_the_other_markers_and_the_chips_after_the_state(): void
    {
        $tile = $this->tile(MarkerMother::markers(
            MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]),
            MarkerMother::marker('run_open', MarkerKind::Working),
            MarkerMother::marker('unmanaged', MarkerKind::Chip),
        ));

        $tooltip = $tile->filter('.lp-tooltip');
        self::assertSame(['checks_failed', 'run_open', 'unmanaged'], $tooltip->filter('[data-marker-key]')->each(static fn (Crawler $row): string => (string) $row->attr('data-marker-key')));
        self::assertStringContainsString('A worker runs on this card.', $tooltip->filter('[data-marker-key="run_open"]')->text());
        self::assertStringContainsString('Unmanaged: the workflow does not act on this card.', $tooltip->filter('[data-marker-key="unmanaged"]')->text());
        self::assertCount(1, $tile->filter('.lp-state-mark'));
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
        self::assertStringContainsString('Unmanaged', $mark->filter('[data-marker-key="unmanaged"]')->text());
        self::assertCount(0, $mark->filter('a'));
        self::assertCount(1, $row->filter('.lp-board-list__work > strong'));
        self::assertCount(0, $row->filter('[data-card-badges], .lp-status-chip'));
    }

    public function test_a_row_with_only_chips_draws_the_neutral_mark(): void
    {
        $row = $this->renderRow($this->storedCard(), MarkerMother::markers(MarkerMother::marker('unmanaged', MarkerKind::Chip)));

        self::assertCount(1, $row->filter('.lp-state-mark--chip'));
        self::assertSame('Note', trim($row->filter('.lp-tooltip__title')->text()));
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

    private function renderTile(Card $card, ?StoredCardMarkers $markers, ?CardRunWarning $runWarning = null): Crawler
    {
        $html = static::getContainer()->get(Environment::class)->render('@Board/_card.html.twig', [
            'card' => $card,
            'pendingComments' => 0,
            'documentCount' => 0,
            'markers' => $markers,
            'runWarning' => $runWarning,
        ]);

        return new Crawler($html)->filter('article.lp-board-card');
    }

    private function renderRow(Card $card, ?StoredCardMarkers $markers): Crawler
    {
        $html = static::getContainer()->get(Environment::class)->render('@Board/_card_row.html.twig', [
            'card' => $card,
            'column' => $card->column,
            'feedbackCount' => 0,
            'documentCount' => 0,
            'progress' => null,
            'runWarning' => null,
            'markers' => $markers,
        ]);

        return new Crawler($html)->filter('a.lp-board-list__row');
    }
}
