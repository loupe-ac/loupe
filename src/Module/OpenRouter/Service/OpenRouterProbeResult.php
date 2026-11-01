<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

enum OpenRouterProbeResult
{
    case Ok;
    case NotManagementKey;
    case Refused;
    case Unreachable;
}
