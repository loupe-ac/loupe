<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Doctrine\SearchLanguage;
use Doctrine\ORM\QueryBuilder;

/**
 * The full-text match and rank of an item alias, as DQL and as native SQL.
 *
 * The match has one branch per language with a constant configuration, so
 * Postgres can use the GIN index. The configuration comes from the enum, never
 * from input.
 */
final readonly class InboxSearchPredicate
{
    public const string PARAMETER = 'search';

    /** @param list<SearchLanguage> $languages */
    public static function match(QueryBuilder $qb, string $alias, array $languages, string $query): string
    {
        $branches = [];
        foreach ($languages as $index => $language) {
            $branches[] = \sprintf(
                "(%s.searchLanguage = :searchLanguage%d AND TSMATCH(%s.searchVector, WEBSEARCH_TO_TSQUERY('%s', :%s)) = true)",
                $alias,
                $index,
                $alias,
                $language->value,
                self::PARAMETER,
            );
            $qb->setParameter('searchLanguage'.$index, $language);
        }
        $qb->setParameter(self::PARAMETER, $query);

        return '('.implode(' OR ', $branches).')';
    }

    public static function rank(string $alias): string
    {
        return \sprintf('TS_RANK(%s.searchVector, WEBSEARCH_TO_TSQUERY(CAST(%s.searchLanguage AS regconfig), :%s))', $alias, $alias, self::PARAMETER);
    }

    /**
     * The native SQL twin of match(), for a query that DQL cannot write.
     *
     * @param list<SearchLanguage> $languages
     * @param array<string, mixed> $parameters receives the values the fragment names
     */
    public static function matchSql(string $alias, array $languages, string $query, array &$parameters): string
    {
        $branches = [];
        foreach ($languages as $index => $language) {
            $branches[] = \sprintf(
                "(%s.search_language = :searchLanguage%d AND %s.search_vector @@ websearch_to_tsquery('%s', :%s))",
                $alias,
                $index,
                $alias,
                $language->value,
                self::PARAMETER,
            );
            $parameters['searchLanguage'.$index] = $language->value;
        }
        $parameters[self::PARAMETER] = $query;

        return '('.implode(' OR ', $branches).')';
    }

    /** The native SQL twin of rank(). */
    public static function rankSql(string $alias): string
    {
        return \sprintf('ts_rank(%s.search_vector, websearch_to_tsquery(CAST(%s.search_language AS regconfig), :%s))', $alias, $alias, self::PARAMETER);
    }
}
