<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Doctrine\SearchLanguage;
use App\Module\Board\Command\SearchBoardHandler;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardSearchTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardSearchToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardSearchTool $tool;
    private CardCreateTool $createTool;
    private CardUpdateTool $updateTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardSearchTool::class);
        self::assertInstanceOf(CardSearchTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;

        $updateTool = self::getContainer()->get(CardUpdateTool::class);
        self::assertInstanceOf(CardUpdateTool::class, $updateTool);
        $this->updateTool = $updateTool;
    }

    public function test_a_blank_query_is_refused_rather_than_read_as_an_empty_board(): void
    {
        $this->boardWith('card-search-blank');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass a query to search for.');
        ($this->tool)('   ');
    }

    public function test_a_title_word_finds_the_card(): void
    {
        $this->boardWith('card-search-title');

        $result = ($this->tool)('mailpit');

        self::assertSame(1, $result['total']);
        self::assertSame('Give each worktree its own Mailpit', $result['cards'][0]['title']);
    }

    /** The point of indexing the body: a topic is often named only there. */
    public function test_a_word_that_lives_only_in_a_body_finds_the_card(): void
    {
        $this->boardWith('card-search-body');

        $result = ($this->tool)('kestrel');

        self::assertSame(1, $result['total']);
        // The word is in this card's body and in no card's title, so a
        // title-only index answers nothing here.
        self::assertSame('Rotate the signing key', $result['cards'][0]['title']);
        self::assertStringNotContainsStringIgnoringCase('kestrel', $result['cards'][0]['title']);
    }

    /** The other half of the decision: "yes, and it is already done" is a true answer. */
    public function test_a_done_card_is_returned(): void
    {
        $this->boardWith('card-search-done');

        $result = ($this->tool)('hedgehog');

        self::assertSame(1, $result['total']);
        self::assertSame('done', $result['cards'][0]['status']);
        self::assertSame('Footer was fixed once already', $result['cards'][0]['title']);
    }

    public function test_a_piece_from_inside_a_word_finds_the_card(): void
    {
        $this->boardWith('card-search-substring');

        // "pag" starts "Paginate" and "gina" sits in its middle; the guard
        // proves the board holds the card the two queries must find.
        self::assertSame(1, ($this->tool)('paginate')['total']);
        self::assertSame(1, ($this->tool)('PAG')['total']);
        self::assertSame(1, ($this->tool)('gina')['total']);
        self::assertSame(0, ($this->tool)('ginb')['total']);
    }

    public function test_a_title_that_matches_through_a_piece_of_a_word_outranks_a_body_match(): void
    {
        $this->boardWith('card-search-title-substring');
        // The title match is created first, so the newest-first tiebreak would put it last.
        ($this->createTool)('Pages of the board', 'Unrelated text.', 'tooling');
        ($this->createTool)('Named in the body only', 'The pagination helper pages through rows.', 'docs');

        $titles = array_column(($this->tool)('pag')['cards'], 'title');

        self::assertSame('Pages of the board', $titles[0]);
    }

    public function test_quotes_minus_and_or_narrow_the_cards(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-search-operators'));
        ($this->createTool)('Board search', 'Finds cards.', 'feature');
        ($this->createTool)('Search board', 'Backlog too.', 'feature');
        ($this->createTool)('Mailpit sidecar', 'Search is not here.', 'tooling');

        self::assertSame(['Board search'], array_column(($this->tool)('"board search"')['cards'], 'title'));
        self::assertEqualsCanonicalizing(
            ['Board search', 'Mailpit sidecar'],
            array_column(($this->tool)('search -backlog')['cards'], 'title'),
        );
        self::assertEqualsCanonicalizing(
            ['Search board', 'Mailpit sidecar'],
            array_column(($this->tool)('mailpit or backlog')['cards'], 'title'),
        );
        self::assertEqualsCanonicalizing(
            ['Board search', 'Search board'],
            array_column(($this->tool)('-mailpit board')['cards'], 'title'),
            'a query of an exclusion and a word combines both',
        );
        self::assertEqualsCanonicalizing(
            ['Board search', 'Mailpit sidecar'],
            array_column(($this->tool)('-backlog')['cards'], 'title'),
            'a query of an exclusion alone keeps every card without the word',
        );
    }

    public function test_a_number_finds_its_card_first(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-search-number'));
        $first = ($this->createTool)('Alpha', 'Body', 'feature');
        ($this->createTool)('Mentions 1 in passing', 'Body', 'feature');
        $this->em->clear();

        $rows = ($this->tool)('#'.$first['number'])['cards'];

        self::assertSame($first['cardId'], $rows[0]['cardId']);
        self::assertGreaterThanOrEqual(2, \count($rows), 'the other card that holds the digit is still a match');
    }

    public function test_a_query_of_punctuation_only_matches_no_card(): void
    {
        $this->boardWith('card-search-punctuation');

        // The guard: a query with a word finds a card, so the empty answers
        // come from the query and not from an empty board.
        self::assertSame(1, ($this->tool)('kestrel')['total']);
        foreach (['!!!', '#', '"', '-', '- -'] as $query) {
            self::assertSame(0, ($this->tool)($query)['total'], $query);
        }
    }

    public function test_a_title_match_outranks_a_body_match(): void
    {
        $this->boardWith('card-search-rank');
        // The title match is created first, so the newest-first tiebreak would put it last.
        ($this->createTool)('Gannet tooling', 'Unrelated text.', 'tooling');
        ($this->createTool)('Named in the body only', 'The gannet is mentioned here.', 'docs');

        $titles = array_column(($this->tool)('gannet')['cards'], 'title');

        self::assertSame(['Gannet tooling', 'Named in the body only'], $titles);
    }

    public function test_an_edited_body_is_reindexed(): void
    {
        $this->boardWith('card-search-reindex');
        $card = ($this->createTool)('Nothing special', 'The body says albatross.', 'feature');
        // Guard: without this the assertions below also pass on a card that was
        // never indexed at all.
        self::assertSame(1, ($this->tool)('albatross')['total']);

        ($this->updateTool)($card['cardId'], body: 'The body now says narwhal.');

        self::assertSame(0, ($this->tool)('albatross')['total']);
        self::assertSame(1, ($this->tool)('narwhal')['total']);
    }

    public function test_an_edited_title_is_reindexed(): void
    {
        $this->boardWith('card-search-retitle');
        $card = ($this->createTool)('Wombat handling', 'Body.', 'feature');
        self::assertSame(1, ($this->tool)('wombat')['total']);

        ($this->updateTool)($card['cardId'], title: 'Capybara handling');

        self::assertSame(0, ($this->tool)('wombat')['total']);
        self::assertSame(1, ($this->tool)('capybara')['total']);
    }

    public function test_another_projects_cards_are_not_found(): void
    {
        $this->boardWith('card-search-mine');
        $this->actAsMcpTokenBoundTo($this->makeProject('card-search-theirs'));

        self::assertSame(0, ($this->tool)('mailpit')['total']);
    }

    public function test_a_number_does_not_find_another_projects_card_that_holds_it(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-search-number-theirs'));
        ($this->createTool)('Ticket 7 in the other project', 'Body', 'feature');
        $this->actAsMcpTokenBoundTo($this->makeProject('card-search-number-mine'));
        ($this->createTool)('Ticket 7 in this project', 'Body', 'feature');
        $this->em->clear();

        $titles = array_column(($this->tool)('7')['cards'], 'title');

        self::assertSame(['Ticket 7 in this project'], $titles);
    }

    public function test_a_row_is_the_same_summary_card_list_returns(): void
    {
        $this->boardWith('card-search-summary');

        $row = ($this->tool)('mailpit')['cards'][0];

        self::assertSame(
            ['cardId', 'number', 'title', 'type', 'status', 'reporter', 'parentCardId', 'updatedAt', 'state'],
            array_keys($row),
        );
    }

    public function test_a_page_is_cut_from_the_matches_and_total_counts_them_all(): void
    {
        $this->boardWith('card-search-paging');
        ($this->createTool)('Otter one', 'Body.', 'feature');
        ($this->createTool)('Otter two', 'Body.', 'feature');
        ($this->createTool)('Otter three', 'Body.', 'feature');

        $first = ($this->tool)('otter', perPage: 2);
        $second = ($this->tool)('otter', page: 2, perPage: 2);

        self::assertCount(2, $first['cards']);
        self::assertSame(3, $first['total']);
        self::assertTrue($first['hasMore']);

        self::assertCount(1, $second['cards']);
        self::assertSame(3, $second['total']);
        self::assertFalse($second['hasMore']);
    }

    public function test_a_page_past_the_end_is_empty_rather_than_an_error(): void
    {
        $this->boardWith('card-search-overrun');

        $result = ($this->tool)('mailpit', page: 99);

        self::assertSame([], $result['cards']);
        self::assertSame(1, $result['total']);
        self::assertFalse($result['hasMore']);
    }

    public function test_the_largest_page_number_reads_empty_rather_than_overflowing(): void
    {
        $this->boardWith('card-search-overflow');

        $result = ($this->tool)('mailpit', page: \PHP_INT_MAX);

        self::assertSame([], $result['cards']);
        self::assertSame(1, $result['total']);
    }

    public function test_page_and_per_page_are_clamped_rather_than_refused(): void
    {
        $this->boardWith('card-search-clamp');

        self::assertSame(1, ($this->tool)('mailpit', page: -4)['page']);
        self::assertSame(1, ($this->tool)('mailpit', perPage: 0)['perPage']);
        self::assertSame(SearchBoardHandler::MAX_PER_PAGE, ($this->tool)('mailpit', perPage: 500)['perPage']);
    }

    /**
     * The substring search reads the text itself, so a project language other
     * than the default does not change what finds a card.
     */
    public function test_a_card_in_a_french_project_is_found_by_its_text(): void
    {
        $project = $this->makeProject('card-search-language');
        $project->searchLanguage = SearchLanguage::French;
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        ($this->createTool)('Les cartes du tableau', 'Le corps parle de goeland.', 'feature');

        self::assertSame(1, ($this->tool)('goeland')['total']);
    }

    public function test_a_page_of_rows_reads_the_pull_request_links_in_one_query(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-search-links'));
        foreach ([1, 2, 3] as $number) {
            ($this->createTool)('Kestrel '.$number, 'Body', 'feature', pullRequestUrls: ['https://github.com/loupe-ac/loupe/pull/'.$number]);
        }
        $this->em->clear();
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $rows = ($this->tool)('kestrel')['cards'];

        self::assertCount(3, $rows);
        $statements = array_merge(...array_values($queries->getData()));
        self::assertNotEmpty($statements);
        $reads = array_filter($statements, static fn (array $query): bool => str_contains((string) $query['sql'], 'FROM board_card_pull_requests'));
        self::assertLessThanOrEqual(1, \count($reads));
    }

    private function boardWith(string $label): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject($label));

        ($this->createTool)('Give each worktree its own Mailpit', 'Two runs read one inbox.', 'tooling');
        ($this->createTool)('Rotate the signing key', 'The kestrel host holds it.', 'security');
        ($this->createTool)('Paginate the board', 'One page at a time.', 'feature');
        ($this->createTool)('Footer was fixed once already', 'A hedgehog sat on it.', 'bug', status: 'done');
    }
}
