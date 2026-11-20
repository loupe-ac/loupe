<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** What a worker run signal says changed, so a page can skip a change it does not show. */
enum WorkerRunChangeKind: string
{
    /** A run was added, moved to another state, closed or held, or a bridge changed. */
    case State = 'state';

    /** The tokens or the cost of a run changed. */
    case Usage = 'usage';
}
