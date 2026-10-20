<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

enum ReviewSurface: string
{
    case Web = 'web';
    case IosSimulator = 'ios-simulator';
}
