<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SearchQuery;
use App\Doctrine\SearchTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    /**
     * @param list<list<string>> $expected each clause as its terms, an excluded term with a leading minus
     */
    #[DataProvider('queries')]
    public function test_a_query_splits_into_clauses_of_terms(string $raw, array $expected): void
    {
        $clauses = array_map(
            static fn (array $clause): array => array_map(static fn (SearchTerm $term): string => ($term->excluded ? '-' : '').$term->text, $clause),
            SearchQuery::parse($raw)->clauses,
        );

        self::assertSame($expected, $clauses);
    }

    /**
     * @return iterable<string, array{string, list<list<string>>}>
     */
    public static function queries(): iterable
    {
        yield 'plain words are all required' => ['Board Search', [['board'], ['search']]];
        yield 'punctuation separates words' => ['top-b', [['top'], ['b']]];
        yield 'a quoted phrase is one term' => ['"Board  search!" x', [['board search'], ['x']]];
        yield 'an unclosed quote still reads' => ['"board search', [['board search']]];
        yield 'a minus excludes a word' => ['search -backlog', [['search'], ['-backlog']]];
        yield 'a minus excludes a phrase' => ['-"back log"', [['-back log']]];
        yield 'or joins the terms around it' => ['mailpit OR backlog board', [['mailpit', 'backlog'], ['board']]];
        yield 'or with nothing before it is ignored' => ['or board', [['board']]];
        yield 'or at the end is ignored' => ['board or', [['board']]];
        yield 'a quoted or is a word' => ['"or" board', [['or'], ['board']]];
        yield 'punctuation alone holds no term' => ['!!! # - "" ()', []];
        yield 'digits and non-ascii letters are words' => ['Été 2026', [['été'], ['2026']]];
        yield 'at most eight terms' => ['a b c d e f g h i j', [['a'], ['b'], ['c'], ['d'], ['e'], ['f'], ['g'], ['h']]];
    }

    public function test_a_term_is_cut_at_the_longest_length(): void
    {
        $query = SearchQuery::parse(str_repeat('a', 100));

        self::assertSame(str_repeat('a', SearchQuery::MAX_TERM_LENGTH), $query->clauses[0][0]->text);
    }

    public function test_the_php_match_follows_the_same_rule_as_the_sql_one(): void
    {
        $query = SearchQuery::parse('"board sea" -backlog');

        self::assertTrue($query->matchesText('The Board Search'));
        self::assertFalse($query->matchesText('The board search backlog'));
        self::assertFalse($query->matchesText('The board'));
        self::assertFalse(SearchQuery::parse('!!!')->matchesText('anything'));
    }
}
