<?php

declare(strict_types=1);

namespace App\Module\Readiness\Entity;

enum DiscoveryRunState: string
{
    case Requested = 'requested';
    case Reported = 'reported';
    case Revising = 'revising';
    case Failed = 'failed';
    case Done = 'done';
    /** A newer run replaced the report before the owner gave a verdict. */
    case Superseded = 'superseded';
}
