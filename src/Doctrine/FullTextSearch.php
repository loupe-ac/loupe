<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * This application's full-text search policy: how much a title outweighs a body.
 *
 * Kept here rather than pushed into a shared bundle, and kept at all rather than
 * dissolved into the martin-georgiev/postgresql-for-doctrine functions that now
 * emit the SQL. Both for the same reason: these are product decisions, not
 * Doctrine plumbing. The library supplies `to_tsvector`, `setweight` and `@@`;
 * it has no opinion about titles mattering more, and neither would a reusable
 * bundle.
 *
 * The inbox and worker run indexers both need the weights in raw SQL. Delete
 * this class and 'A' and 'B' become string literals in both places, free to drift.
 *
 * The stemming language is not here. The inbox indexer reads it off each row
 * {@see SearchLanguage}, and the worker run indexer names its own.
 */
final class FullTextSearch
{
    /** Title matches outrank body matches; ts_rank reads the labels off the vector. */
    public const string TITLE_WEIGHT = 'A';

    public const string BODY_WEIGHT = 'B';
}
