<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Doctrine\SearchLanguage;
use Doctrine\ORM\QueryBuilder;

/**
 * The full-text match and rank of an item alias, as DQL.
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
}
