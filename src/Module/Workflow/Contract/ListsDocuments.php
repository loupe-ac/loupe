<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Facts that hold the documents linked to the card. */
interface ListsDocuments
{
    /** @var list<DocumentFacts> */
    public array $documents { get; }
}
