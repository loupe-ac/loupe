<?php

declare(strict_types=1);

namespace App\Module\Review\View;

/** The order a page of the document list walks in. The value is the sort name that a cursor carries. */
enum DocumentPageOrder: string
{
    case Oldest = 'oldest';
    case Newest = 'newest';

    /** A series filter without a sort reads the documents by their position. */
    case Series = 'series';

    /** A search without a sort puts the documents with every word in the title first. */
    case Relevance = 'relevance';
}
