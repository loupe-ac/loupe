<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\QueryBuilder;

/**
 * Adds a {@see SearchQuery} to a DQL query as `LIKE '%term%'` tests on lowercased
 * columns, which the trigram indexes on `lower(column)` serve.
 *
 * Every term binds its own parameter, so the typed text never joins the DQL. The
 * term text holds only letters, digits and spaces, so it needs no LIKE escape.
 */
final readonly class SubstringSearch
{
    /**
     * Narrows the query to the rows that match.
     *
     * @param list<string> $columns the property names of the entity to search, such as title and body
     */
    public static function andMatches(QueryBuilder $qb, string $alias, array $columns, SearchQuery $query): void
    {
        $qb->andWhere(self::expression($qb, $alias, $columns, $query));
    }

    /**
     * The condition for a query, with its parameters bound. A query with no term
     * gives a condition that matches no row, so a punctuation-only query never
     * returns the whole project.
     *
     * @param list<string> $columns
     */
    public static function expression(QueryBuilder $qb, string $alias, array $columns, SearchQuery $query): string
    {
        if (!$query->hasTerms()) {
            return '1 = 0';
        }

        $clauses = [];
        foreach ($query->clauses as $c => $clause) {
            $alternatives = [];
            foreach ($clause as $t => $term) {
                $name = \sprintf('search_%d_%d', $c, $t);
                $qb->setParameter($name, '%'.$term->text.'%');
                $any = '('.implode(' OR ', array_map(
                    static fn (string $column): string => \sprintf('LOWER(%s.%s) LIKE :%s', $alias, $column, $name),
                    $columns,
                )).')';
                $alternatives[] = $term->excluded ? 'NOT '.$any : $any;
            }
            $clauses[] = '('.implode(' OR ', $alternatives).')';
        }

        return '('.implode(' AND ', $clauses).')';
    }

    /**
     * Puts the rows that hold every wanted term in the title above the rows that
     * hold them only in the body. A query with only excluded terms adds nothing.
     */
    public static function addTitleFirst(QueryBuilder $qb, string $alias, string $titleColumn, SearchQuery $query): void
    {
        $tests = [];
        foreach ($query->clauses as $c => $clause) {
            if ([] === array_filter($clause, static fn (SearchTerm $term): bool => $term->excluded)) {
                $tests[] = '('.implode(' OR ', array_map(
                    static fn (int $t): string => \sprintf('LOWER(%s.%s) LIKE :search_%d_%d', $alias, $titleColumn, $c, $t),
                    array_keys($clause),
                )).')';
            }
        }
        if ([] === $tests) {
            return;
        }

        $qb->addSelect('CASE WHEN '.implode(' AND ', $tests).' THEN 1 ELSE 0 END AS HIDDEN titleHit')
            ->addOrderBy('titleHit', 'DESC');
    }
}
