<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

enum PublicLinkKind: string
{
    case Graph = 'graph';
    case Experiment = 'experiment';
}
