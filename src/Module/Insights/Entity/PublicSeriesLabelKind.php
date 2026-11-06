<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

enum PublicSeriesLabelKind: string
{
    case Account = 'account';
    case Bridge = 'bridge';
}
